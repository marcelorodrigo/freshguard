package web

import (
	"io/fs"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"testing/fstest"
)

func testFS() fstest.MapFS {
	return fstest.MapFS{
		"index.html":        {Data: []byte("<!doctype html><div id=app></div>")},
		"assets/app-abc.js": {Data: []byte("console.log('app')")},
		"favicon.svg":       {Data: []byte("<svg/>")},
	}
}

func TestHandlerServesIndex(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if got := rec.Body.String(); got != "<!doctype html><div id=app></div>" {
		t.Errorf("body = %q", got)
	}
	if got := rec.Header().Get("Cache-Control"); got != "no-cache" {
		t.Errorf("Cache-Control = %q, want no-cache", got)
	}
}

func TestHandlerServesHashedAssetsImmutable(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/assets/app-abc.js", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if got := rec.Header().Get("Cache-Control"); got != "public, max-age=31536000, immutable" {
		t.Errorf("Cache-Control = %q", got)
	}
	if got := rec.Header().Get("Content-Type"); got != "text/javascript; charset=utf-8" {
		t.Errorf("Content-Type = %q", got)
	}
}

func TestHandlerFallsBackToIndexForClientRoutes(t *testing.T) {
	t.Parallel()

	for _, name := range []string{"/items", "/items/0190a1b2", "/locations/abc/batches"} {
		rec := httptest.NewRecorder()
		Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, name, nil))

		if rec.Code != http.StatusOK {
			t.Errorf("%s: status = %d, want 200", name, rec.Code)
			continue
		}
		if got := rec.Body.String(); got != "<!doctype html><div id=app></div>" {
			t.Errorf("%s: body = %q, want index.html", name, got)
		}
	}
}

func TestHandlerHidesEmptyDistBehindHint(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(fstest.MapFS{}).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/items", nil))

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", rec.Code)
	}
	if got := rec.Body.String(); got == "" {
		t.Error("body is empty, want the build hint")
	}
}

func TestHandlerMissingAssetIsNotFound(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/assets/app-gone.js", nil))

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want 404", rec.Code)
	}
}

func TestHandlerRejectsWrites(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/items", nil))

	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", rec.Code)
	}
}

func TestHandlerTraversalStaysInsideTheSPA(t *testing.T) {
	t.Parallel()

	rec := httptest.NewRecorder()
	Handler(testFS()).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/../../etc/passwd", nil))

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rec.Code)
	}
	if got := rec.Body.String(); strings.Contains(got, "root:") {
		t.Errorf("body = %q, leaked a file outside the SPA", got)
	}
}

func TestDistIsEmbedded(t *testing.T) {
	t.Parallel()

	if _, err := fs.Stat(Dist(), "."); err != nil {
		t.Fatalf("Dist(): %v", err)
	}
}
