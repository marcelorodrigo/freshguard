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
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
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
	// Now overrides the clock in tests. Nil uses time.Now.
	Now func() time.Time
}

// New builds the root handler: the API, the JSON envelope, and the SPA.
func New(opts Options) http.Handler {
	srv := newServer(opts)
	return logRequests(opts.Logger, srv.routes())
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

	return &server{
		version:      opts.Version,
		logger:       opts.Logger,
		web:          opts.Web,
		auth:         opts.Auth,
		now:          opts.Now,
		loginLimiter: newLoginLimiter(),
	}
}

type server struct {
	version      string
	logger       *slog.Logger
	web          fs.FS
	auth         *auth.Service
	now          func() time.Time
	loginLimiter *loginLimiter
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

func logRequests(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(recorder, r)

		logger.LogAttrs(r.Context(), slog.LevelInfo, "request",
			slog.String("method", r.Method),
			slog.String("path", r.URL.Path),
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
