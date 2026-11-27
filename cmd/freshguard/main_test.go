package main

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestRunVersion(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	if err := run(context.Background(), []string{"version"}, func(string) string { return "" }, &stdout); err != nil {
		t.Fatalf("run(version): %v", err)
	}

	if got := strings.TrimSpace(stdout.String()); got == "" {
		t.Fatal("version printed nothing")
	}
}

func TestRunHelp(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	if err := run(context.Background(), []string{"help"}, func(string) string { return "" }, &stdout); err != nil {
		t.Fatalf("run(help): %v", err)
	}

	if !strings.Contains(stdout.String(), "freshguard healthcheck") {
		t.Errorf("help text = %q, want the healthcheck subcommand", stdout.String())
	}
}

func TestRunUnknownCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	err := run(context.Background(), []string{"migrate"}, func(string) string { return "" }, &stdout)
	if err == nil {
		t.Fatal("run(migrate) = nil error, want failure")
	}
	if !strings.Contains(err.Error(), "migrate") {
		t.Errorf("error = %v, want the unknown command named", err)
	}
}

func TestHealthcheck(t *testing.T) {
	t.Parallel()

	mux := http.NewServeMux()
	mux.HandleFunc("GET /api/v1/health", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	ts := httptest.NewServer(mux)
	t.Cleanup(ts.Close)

	if err := healthcheck(context.Background(), ts.URL); err != nil {
		t.Fatalf("healthcheck: %v", err)
	}
}

func TestHealthcheckFailsOnUnhealthyServer(t *testing.T) {
	t.Parallel()

	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	t.Cleanup(ts.Close)

	if err := healthcheck(context.Background(), ts.URL); err == nil {
		t.Fatal("healthcheck = nil error, want failure")
	}
}

func TestHealthURL(t *testing.T) {
	t.Parallel()

	tests := map[string]string{
		":8080":            "http://127.0.0.1:8080/api/v1/health",
		"127.0.0.1:9000":   "http://127.0.0.1:9000/api/v1/health",
		"http://app:8080":  "http://app:8080/api/v1/health",
		"https://app.test": "https://app.test/api/v1/health",
	}

	for addr, want := range tests {
		if got := healthURL(addr); got != want {
			t.Errorf("healthURL(%q) = %q, want %q", addr, got, want)
		}
	}
}
