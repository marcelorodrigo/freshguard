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

	"github.com/marcelorodrigo/freshguard/internal/web"
)

// CodeNotFound is the envelope code for an unknown route.
const CodeNotFound = "not_found"

// Options configures the root handler.
type Options struct {
	// Version is the build version reported by the health endpoint.
	Version string
	// Logger receives one line per request. Nil discards them.
	Logger *slog.Logger
	// Web is the SPA filesystem. Nil embeds the build in this binary.
	Web fs.FS
	// Now overrides the clock in tests. Nil uses time.Now.
	Now func() time.Time
}

// New builds the root handler: the API, the JSON envelope, and the SPA.
func New(opts Options) http.Handler {
	if opts.Logger == nil {
		opts.Logger = slog.New(slog.DiscardHandler)
	}
	if opts.Now == nil {
		opts.Now = time.Now
	}
	if opts.Web == nil {
		opts.Web = web.Dist()
	}

	srv := &server{version: opts.Version, now: opts.Now}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /api/v1/health", srv.health)
	mux.HandleFunc("/api", srv.notFound)
	mux.HandleFunc("/api/", srv.notFound)
	mux.Handle("/", web.Handler(opts.Web))

	return logRequests(opts.Logger, mux)
}

type server struct {
	version string
	now     func() time.Time
}

type healthResponse struct {
	Status  string `json:"status"`
	Version string `json:"version"`
	Time    string `json:"time"`
}

func (s *server) health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, healthResponse{
		Status:  "ok",
		Version: s.version,
		Time:    s.now().UTC().Format(time.RFC3339),
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
