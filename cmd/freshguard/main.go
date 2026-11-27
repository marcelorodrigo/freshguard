// Command freshguard is the FreshGuard server: one binary that answers the
// JSON API and serves the embedded SPA.
//
// See docs/architecture.md for the package map and the dev loop.
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	_ "time/tzdata" // FRESHGUARD_TZ works in distroless images.

	"github.com/marcelorodrigo/freshguard/internal/config"
	"github.com/marcelorodrigo/freshguard/internal/httpapi"
)

// version is replaced at build time: -X main.version=<git describe>.
var version = "dev"

const shutdownTimeout = 10 * time.Second

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if err := run(ctx, os.Args[1:], os.Getenv, os.Stdout); err != nil {
		fmt.Fprintln(os.Stderr, "freshguard:", err)
		os.Exit(1)
	}
}

func run(ctx context.Context, args []string, getenv func(string) string, stdout io.Writer) error {
	command := "serve"
	if len(args) > 0 {
		command = args[0]
	}

	// version and help answer without configuration: they are how an operator
	// checks a build and reads the flags before anything can be misconfigured.
	switch command {
	case "version":
		_, err := fmt.Fprintln(stdout, version)
		return err
	case "help", "-h", "--help":
		usage(stdout)
		return nil
	}

	cfg, err := config.Load(getenv)
	if err != nil {
		return err
	}

	switch command {
	case "serve":
		return serve(ctx, cfg)
	case "healthcheck":
		return healthcheck(ctx, cfg.Addr)
	default:
		usage(stdout)
		return fmt.Errorf("unknown command %q", command)
	}
}

func usage(w io.Writer) {
	fmt.Fprint(w, `freshguard — household inventory server

usage:
  freshguard [serve]      run the server (default)
  freshguard healthcheck  exit 0 when GET /api/v1/health answers
  freshguard version      print the build version
  freshguard help         print this text

configuration:
  FRESHGUARD_ADDR           listen address (default :8080)
  FRESHGUARD_DATA_DIR       directory holding freshguard.db (default ./data)
  FRESHGUARD_BASE_URL        absolute URL used in CLI links
  FRESHGUARD_LOG_LEVEL      debug, info, warn, error (default info)
  FRESHGUARD_SESSION_TTL    sliding session lifetime (default 720h)
  FRESHGUARD_OFF_BASE_URL   Open Food Facts endpoint
  FRESHGUARD_TZ             zone for date-only logic and logs
`)
}

func serve(ctx context.Context, cfg config.Config) error {
	logger := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel}))
	slog.SetDefault(logger)

	time.Local = cfg.TZ

	var handler http.Handler = httpapi.New(httpapi.Options{Version: version, Logger: logger})

	listener, err := net.Listen("tcp", cfg.Addr)
	if err != nil {
		return err
	}

	server := &http.Server{
		Handler:           handler,
		ReadHeaderTimeout: 10 * time.Second,
	}

	logger.LogAttrs(ctx, slog.LevelInfo, "listening",
		slog.String("addr", listener.Addr().String()),
		slog.String("version", version),
		slog.String("data_dir", cfg.DataDir),
	)

	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()

	select {
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
		defer cancel()

		logger.LogAttrs(context.Background(), slog.LevelInfo, "shutting down")
		return server.Shutdown(shutdownCtx)
	case err := <-serveErr:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	}
}

func healthcheck(ctx context.Context, addr string) error {
	target := healthURL(addr)

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return err
	}

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("healthcheck: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("healthcheck: %s answered %d", target, resp.StatusCode)
	}

	return nil
}

// healthURL turns a listen address into the URL of the health endpoint. A
// wildcard listen address is dialled on loopback: 0.0.0.0 and :: mean
// "everywhere", not "here", and not every platform routes them to localhost.
func healthURL(addr string) string {
	scheme := "http://"
	addr = strings.TrimRight(addr, "/")
	if rest, ok := strings.CutPrefix(addr, "https://"); ok {
		scheme, addr = "https://", rest
	} else if rest, ok := strings.CutPrefix(addr, "http://"); ok {
		addr = rest
	}

	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return scheme + addr + "/api/v1/health"
	}

	switch host {
	case "", "0.0.0.0":
		host = "127.0.0.1"
	case "::":
		host = "::1"
	}

	return scheme + net.JoinHostPort(host, port) + "/api/v1/health"
}
