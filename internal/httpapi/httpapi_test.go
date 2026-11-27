package httpapi

import (
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"testing/fstest"
	"time"
)

const indexHTML = "<!doctype html><div id=app></div>"

func testHandler(t *testing.T) http.Handler {
	t.Helper()

	return New(Options{
		Version: "1.0.0",
		Logger:  slog.New(slog.DiscardHandler),
		Web: fstest.MapFS{
			"index.html": {Data: []byte(indexHTML)},
		},
		Now: func() time.Time { return time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC) },
	})
}

func TestHealth(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	testHandler(t).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/api/v1/health", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if got := rec.Header().Get("Content-Type"); got != "application/json; charset=utf-8" {
		t.Errorf("Content-Type = %q", got)
	}

	var body struct {
		Status  string `json:"status"`
		Version string `json:"version"`
		Time    string `json:"time"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode: %v", err)
	}

	if body.Status != "ok" {
		t.Errorf("status field = %q, want ok", body.Status)
	}
	if body.Version != "1.0.0" {
		t.Errorf("version = %q, want 1.0.0 (injected at build time)", body.Version)
	}
	if _, err := time.Parse(time.RFC3339, body.Time); err != nil {
		t.Errorf("time = %q, want RFC3339: %v", body.Time, err)
	}
	if body.Time != "2026-01-24T08:27:26Z" {
		t.Errorf("time = %q, want the UTC clock", body.Time)
	}
}

func TestHealthAnswersHead(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	testHandler(t).ServeHTTP(rec, httptest.NewRequest(http.MethodHead, "/api/v1/health", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
}

func TestUnknownAPIPathReturnsJSONEnvelope(t *testing.T) {
	t.Parallel()

	for _, path := range []string{"/api/v1/nope", "/api", "/api/"} {
		rec := httptest.NewRecorder()
		testHandler(t).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, path, nil))

		if rec.Code != http.StatusNotFound {
			t.Errorf("%s: status = %d, want 404", path, rec.Code)
			continue
		}
		if got := rec.Header().Get("Content-Type"); got != "application/json; charset=utf-8" {
			t.Errorf("%s: Content-Type = %q, want JSON", path, got)
		}

		var body struct {
			Error struct {
				Code    string `json:"code"`
				Message string `json:"message"`
			} `json:"error"`
		}
		if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
			t.Errorf("%s: decode: %v", path, err)
			continue
		}
		if body.Error.Code != CodeNotFound {
			t.Errorf("%s: code = %q, want %q", path, body.Error.Code, CodeNotFound)
		}
	}
}

func TestUnknownNonAPIPathReturnsSPA(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	testHandler(t).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/items/0190a1b2", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if got := rec.Body.String(); got != indexHTML {
		t.Errorf("body = %q, want index.html", got)
	}
}

func TestWrongMethodOnKnownAPIPathStaysJSON(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	testHandler(t).ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/api/v1/health", nil))

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want 404", rec.Code)
	}
	if got := rec.Header().Get("Content-Type"); got != "application/json; charset=utf-8" {
		t.Errorf("Content-Type = %q, want the error envelope", got)
	}
}
