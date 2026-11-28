// Package httpapi wires HTTP routes to the rest of the application.
//
// Handlers own validation and response shapes; the packages under them own
// SQL and rules. Every error leaves as the envelope described in docs/api.md.
package httpapi

import (
	"encoding/json"
	"io/fs"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
	"github.com/marcelorodrigo/freshguard/internal/web"
)

// Envelope codes from docs/api.md.
const (
	CodeNotFound     = "not_found"
	CodeInvalidInput = "invalid_input"
	CodeUnauthorized = "unauthorized"
	CodeForbidden    = "forbidden"
	CodeBadOrigin    = "bad_origin"
	CodeMustReset    = "must_reset"
	CodeConflict     = "conflict"
	CodeRateLimited  = "rate_limited"
	CodeInternal     = "internal"

	// CodeLocationHasBatches blocks a location delete that still holds stock.
	CodeLocationHasBatches = "location_has_batches"
	// CodeBatchUnavailable is a consume that raced a delete or another consume.
	CodeBatchUnavailable = "batch_unavailable"

	// CodeCannotDeleteSelf blocks a self-delete.
	CodeCannotDeleteSelf = "cannot_delete_self"
	// CodeLastAdmin blocks deleting the only administrator.
	CodeLastAdmin = "last_admin"
	// CodeCannotDemoteSelf blocks an administrator taking their own rights away.
	CodeCannotDemoteSelf = "cannot_demote_self"
)

// Options configures the root handler.
type Options struct {
	// Version is the build version reported by the health endpoint.
	Version string
	// Logger receives one line per request. Nil discards them.
	Logger *slog.Logger
	// Web is the SPA filesystem. Nil embeds the build in this binary.
	Web fs.FS
	// Auth is the account and session service. Required.
	Auth *auth.Service
	// Inventory is the item and tag service. Required.
	Inventory *inventory.Service
	// Now overrides the clock in tests. Nil uses time.Now.
	Now func() time.Time
}

// New builds the root handler: the API, the JSON envelope, and the SPA.
func New(opts Options) http.Handler {
	srv := newServer(opts)
	return logRequests(opts.Logger, srv.rateLimitAPI(srv.routes()))
}

func newServer(opts Options) *server {
	if opts.Logger == nil {
		opts.Logger = slog.New(slog.DiscardHandler)
	}
	if opts.Now == nil {
		opts.Now = time.Now
	}
	if opts.Web == nil {
		opts.Web = web.Dist()
	}
	if opts.Auth == nil {
		panic("httpapi: New requires Options.Auth")
	}
	if opts.Inventory == nil {
		panic("httpapi: New requires Options.Inventory")
	}

	return &server{
		version:      opts.Version,
		logger:       opts.Logger,
		web:          opts.Web,
		auth:         opts.Auth,
		inventory:    opts.Inventory,
		now:          opts.Now,
		loginLimiter: newRateLimiter(loginAttempts, loginWindow),
		apiLimiter:   newRateLimiter(apiRequests, apiWindow),
	}
}

type server struct {
	version      string
	logger       *slog.Logger
	web          fs.FS
	auth         *auth.Service
	inventory    *inventory.Service
	now          func() time.Time
	loginLimiter *rateLimiter
	apiLimiter   *rateLimiter
}

// routes registers every endpoint. Keeping it a method lets a test add a probe
// route to exercise middleware that guards endpoints added later.
func (s *server) routes() *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /api/v1/health", s.health)

	mux.HandleFunc("POST /api/v1/setup", s.setup)
	mux.HandleFunc("POST /api/v1/session", s.login)
	mux.HandleFunc("DELETE /api/v1/session", s.authenticated(s.logout))
	mux.HandleFunc("GET /api/v1/me", s.authenticated(s.me))
	mux.HandleFunc("PATCH /api/v1/me", s.authenticated(s.updateMe))
	mux.HandleFunc("GET /api/v1/password-resets/{token}", s.resetToken)
	mux.HandleFunc("POST /api/v1/password-resets/{token}", s.resetPassword)

	mux.HandleFunc("GET /api/v1/items", s.authenticated(s.listItems))
	mux.HandleFunc("POST /api/v1/items", s.authenticated(s.createItem))
	mux.HandleFunc("POST /api/v1/items/bulk-delete", s.authenticated(s.bulkDeleteItems))
	mux.HandleFunc("GET /api/v1/items/{id}", s.authenticated(s.getItem))
	mux.HandleFunc("PATCH /api/v1/items/{id}", s.authenticated(s.updateItem))
	mux.HandleFunc("DELETE /api/v1/items/{id}", s.authenticated(s.deleteItem))
	mux.HandleFunc("POST /api/v1/items/{id}/batches", s.authenticated(s.createBatch))
	mux.HandleFunc("PATCH /api/v1/batches/{id}", s.authenticated(s.updateBatch))
	mux.HandleFunc("DELETE /api/v1/batches/{id}", s.authenticated(s.deleteBatch))
	mux.HandleFunc("POST /api/v1/batches/{id}/consume", s.authenticated(s.consumeBatch))
	mux.HandleFunc("GET /api/v1/search", s.authenticated(s.search))
	mux.HandleFunc("GET /api/v1/locations", s.authenticated(s.listLocations))
	mux.HandleFunc("POST /api/v1/locations", s.authenticated(s.createLocation))
	mux.HandleFunc("POST /api/v1/locations/bulk-delete", s.authenticated(s.bulkDeleteLocations))
	mux.HandleFunc("PATCH /api/v1/locations/{id}", s.authenticated(s.updateLocation))
	mux.HandleFunc("DELETE /api/v1/locations/{id}", s.authenticated(s.deleteLocation))
	mux.HandleFunc("GET /api/v1/tags", s.authenticated(s.listTags))

	mux.HandleFunc("GET /api/v1/users", s.administrator(s.listUsers))
	mux.HandleFunc("POST /api/v1/users", s.administrator(s.createUser))
	mux.HandleFunc("PATCH /api/v1/users/{id}", s.administrator(s.updateUser))
	mux.HandleFunc("DELETE /api/v1/users/{id}", s.administrator(s.deleteUser))
	mux.HandleFunc("POST /api/v1/users/{id}/password", s.administrator(s.setUserPassword))

	mux.HandleFunc("/api", s.notFound)
	mux.HandleFunc("/api/", s.notFound)
	mux.Handle("/", web.Handler(s.web))

	return mux
}

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	SetupRequired bool   `json:"setup_required"`
	Time          string `json:"time"`
}

func (s *server) health(w http.ResponseWriter, r *http.Request) {
	required, err := s.auth.SetupRequired(r.Context())
	if err != nil {
		s.internal(w, r, "health", err)
		return
	}

	writeJSON(w, http.StatusOK, healthResponse{
		Status:        "ok",
		Version:       s.version,
		SetupRequired: required,
		Time:          s.now().UTC().Format(time.RFC3339),
	})
}

func (s *server) notFound(w http.ResponseWriter, _ *http.Request) {
	writeError(w, http.StatusNotFound, CodeNotFound, "no such endpoint")
}

type errorBody struct {
	Code    string            `json:"code"`
	Message string            `json:"message"`
	Fields  map[string]string `json:"fields,omitempty"`
}

type errorResponse struct {
	Error errorBody `json:"error"`
}

func writeError(w http.ResponseWriter, status int, code, message string) {
	writeJSON(w, status, errorResponse{Error: errorBody{Code: code, Message: message}})
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	body, err := json.Marshal(payload)
	if err != nil {
		http.Error(w, `{"error":{"code":"internal","message":"encoding failed"}}`, http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write(body)
}

// rateLimitAPI applies the general per-address bucket. Health stays exempt so
// a probe cannot exhaust the budget or be refused during a deploy.
func (s *server) rateLimitAPI(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.URL.Path, "/api/") && r.URL.Path != "/api/v1/health" {
			if !s.apiLimiter.allow(clientIP(r), s.now()) {
				writeError(w, http.StatusTooManyRequests, CodeRateLimited, "too many requests")
				return
			}
		}

		next.ServeHTTP(w, r)
	})
}

func logRequests(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(recorder, r)

		route := r.Pattern
		if route == "" {
			route = "unmatched"
		}

		logger.LogAttrs(r.Context(), slog.LevelInfo, "request",
			slog.String("method", r.Method),
			slog.String("route", route),
			slog.Int("status", recorder.status),
			slog.Duration("duration", time.Since(start)),
		)
	})
}

// statusRecorder captures the status code for the request log.
type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (r *statusRecorder) WriteHeader(status int) {
	r.status = status
	r.ResponseWriter.WriteHeader(status)
}

// Unwrap exposes the original writer to http.ResponseController.
func (r *statusRecorder) Unwrap() http.ResponseWriter {
	return r.ResponseWriter
}
