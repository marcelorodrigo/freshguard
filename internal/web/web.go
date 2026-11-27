// Package web serves the single-page application embedded in the binary.
//
// The build writes Vite output to dist/, which go:embed picks up at compile
// time. A request that names no asset gets index.html so client-side routes
// survive a reload.
package web

import (
	"embed"
	"io/fs"
	"net/http"
	"path"
	"strings"
)

const (
	indexName          = "index.html"
	assetsPrefix       = "/assets/"
	cacheControlHeader = "Cache-Control"
)

//go:embed all:dist
var assets embed.FS

// Dist returns the embedded SPA build directory.
func Dist() fs.FS {
	dist, err := fs.Sub(assets, "dist")
	if err != nil {
		panic("web: embedded dist directory is missing: " + err.Error())
	}
	return dist
}

// Handler serves files from fsys and falls back to index.html for anything
// that is not a file, so the SPA owns the non-API paths. A missing file under
// /assets/ is a 404, never index.html: a missing chunk must fail loudly.
func Handler(fsys fs.FS) http.Handler {
	// FileServerFS resolves and cleans the request path itself; this handler
	// only decides which kind of response a path gets. No request path is ever
	// handed to a file API directly.
	files := http.FileServerFS(fsys)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			w.Header().Set("Allow", "GET, HEAD")
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}

		name := strings.TrimPrefix(path.Clean("/"+r.URL.Path), "/")
		if name == "" {
			name = indexName
		}
		if !fs.ValidPath(name) {
			http.NotFound(w, r)
			return
		}

		if info, err := fs.Stat(fsys, name); err == nil && !info.IsDir() {
			setCacheHeader(w, name)
			files.ServeHTTP(w, r)
			return
		}

		if strings.HasPrefix(r.URL.Path, assetsPrefix) {
			http.NotFound(w, r)
			return
		}

		if _, err := fs.Stat(fsys, indexName); err != nil {
			w.Header().Set(cacheControlHeader, "no-store")
			http.Error(w, "SPA not built: run make web", http.StatusServiceUnavailable)
			return
		}

		w.Header().Set(cacheControlHeader, "no-cache")
		http.ServeFileFS(w, r, fsys, indexName)
	})
}

func setCacheHeader(w http.ResponseWriter, name string) {
	switch {
	case strings.HasPrefix("/"+name, assetsPrefix):
		w.Header().Set(cacheControlHeader, "public, max-age=31536000, immutable")
	case name == indexName:
		w.Header().Set(cacheControlHeader, "no-cache")
	}
}
