package httpapi

import (
	"bytes"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"testing/fstest"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/inventory"
	"github.com/marcelorodrigo/freshguard/internal/store"
)

const indexHTML = "<!doctype html><div id=app></div>"

var testNow = time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC)

// testClock is a mutable clock shared by the service and the handlers.
type testClock struct {
	now time.Time
}

func (c *testClock) Now() time.Time { return c.now }

func (c *testClock) Advance(d time.Duration) { c.now = c.now.Add(d) }

func testAuth(t *testing.T, clock *testClock) (*auth.Service, *inventory.Service, *store.Store) {
	t.Helper()

	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatalf("store.Open: %v", err)
	}
	t.Cleanup(func() {
		if err := st.Close(); err != nil {
			t.Errorf("store.Close: %v", err)
		}
	})

	return auth.New(auth.Options{Store: st, Now: clock.Now}),
		inventory.New(inventory.Options{Store: st, Now: clock.Now}),
		st
}

func testWeb() fstest.MapFS {
	return fstest.MapFS{
		"index.html": {Data: []byte(indexHTML)},
	}
}

// testHandler builds the production root handler from New.
func testHandler(t *testing.T) http.Handler {
	t.Helper()

	clock := &testClock{now: testNow}
	accounts, stock, _ := testAuth(t, clock)

	return New(Options{
		Version:   "1.0.0",
		Logger:    slog.New(slog.DiscardHandler),
		Web:       testWeb(),
		Auth:      accounts,
		Inventory: stock,
		Now:       clock.Now,
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
		Status        string `json:"status"`
		Version       string `json:"version"`
		SetupRequired bool   `json:"setup_required"`
		Time          string `json:"time"`
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
	if !body.SetupRequired {
		t.Error("setup_required = false on an empty database, want true")
	}
	if _, err := time.Parse(time.RFC3339, body.Time); err != nil {
		t.Errorf("time = %q, want RFC3339: %v", body.Time, err)
	}
	if body.Time != "2026-01-24T08:27:26Z" {
		t.Errorf("time = %q, want the UTC clock", body.Time)
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

func TestRequestLogUsesTheRoutePattern(t *testing.T) {
	t.Parallel()

	var logs bytes.Buffer
	logger := slog.New(slog.NewTextHandler(&logs, &slog.HandlerOptions{Level: slog.LevelInfo}))
	clock := &testClock{now: testNow}
	accounts, stock, _ := testAuth(t, clock)
	srv := newServer(Options{
		Version:   "1.0.0",
		Logger:    logger,
		Web:       testWeb(),
		Auth:      accounts,
		Inventory: stock,
		Now:       clock.Now,
	})

	rec := httptest.NewRecorder()
	logRequests(logger, srv.routes()).ServeHTTP(rec,
		httptest.NewRequest(http.MethodGet, "/api/v1/password-resets/secret-token-value", nil))

	if strings.Contains(logs.String(), "secret-token-value") {
		t.Fatalf("request log leaked the token: %s", logs.String())
	}
	if !strings.Contains(logs.String(), "password-resets/{token}") {
		t.Errorf("request log has no route pattern: %s", logs.String())
	}
}
