// Command freshguard is the FreshGuard server: one binary that answers the
// JSON API and serves the embedded SPA.
//
// See docs/architecture.md for the package map and the dev loop.
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
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

	"golang.org/x/term"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/config"
	"github.com/marcelorodrigo/freshguard/internal/httpapi"
	"github.com/marcelorodrigo/freshguard/internal/store"
)

// version is replaced at build time: -X main.version=<git describe>.
var version = "dev"

const (
	shutdownTimeout = 10 * time.Second

	// schemeHTTP and schemeHTTPS name the scheme a self-hosted listener speaks.
	// TLS, when present, terminates at the reverse proxy in front of it.
	schemeHTTP  = "http://"
	schemeHTTPS = "https://"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if err := run(ctx, os.Args[1:], os.Stdin, os.Stdout, os.Stderr, os.Getenv); err != nil {
		fmt.Fprintln(os.Stderr, "freshguard:", err)
		os.Exit(1)
	}
}

func run(ctx context.Context, args []string, stdin io.Reader, stdout, stderr io.Writer, getenv func(string) string) error {
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
	case "user":
		return userCommand(ctx, cfg, args[1:], stdin, stdout, stderr)
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
  freshguard user create --name <name> --email <email> [--admin]
                          create an account; the password is read from stdin
  freshguard user password <email>
                          print a single-use password reset link
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

	listener, err := net.Listen("tcp", cfg.Addr)
	if err != nil {
		return err
	}

	return runServer(ctx, cfg, logger, listener)
}

// runServer serves on an already-open listener, so a caller (or a test) decides
// where the address comes from.
func runServer(ctx context.Context, cfg config.Config, logger *slog.Logger, listener net.Listener) error {
	defer func() { _ = listener.Close() }()

	db, err := store.Open(cfg.DataDir)
	if err != nil {
		return err
	}
	defer func() {
		if err := db.Close(); err != nil {
			logger.LogAttrs(ctx, slog.LevelError, "close database", slog.String("error", err.Error()))
		}
	}()

	accounts := auth.New(auth.Options{Store: db, SessionTTL: cfg.SessionTTL})

	setupRequired, err := accounts.SetupRequired(ctx)
	if err != nil {
		return err
	}

	server := &http.Server{
		Handler: httpapi.New(httpapi.Options{
			Version: version,
			Logger:  logger,
			Auth:    accounts,
		}),
		ReadHeaderTimeout: 10 * time.Second,
	}

	logger.LogAttrs(ctx, slog.LevelInfo, "listening",
		slog.String("addr", listener.Addr().String()),
		slog.String("version", version),
		slog.String("data_dir", cfg.DataDir),
	)

	if setupRequired {
		logger.LogAttrs(ctx, slog.LevelInfo, "setup required",
			slog.String("setup_url", publicURL(cfg)+"/setup"))
	}

	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()

	select {
	case <-ctx.Done():
		// WithoutCancel keeps the signal from aborting the drain it caused.
		shutdownCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), shutdownTimeout)
		defer cancel()

		logger.LogAttrs(shutdownCtx, slog.LevelInfo, "shutting down")
		return server.Shutdown(shutdownCtx)
	case err := <-serveErr:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	}
}

func userCommand(ctx context.Context, cfg config.Config, args []string, stdin io.Reader, stdout, stderr io.Writer) error {
	if len(args) == 0 {
		return errors.New("user: expected create or password")
	}

	switch args[0] {
	case "create":
		return userCreate(ctx, cfg, args[1:], stdin, stdout, stderr)
	case "password":
		return userPassword(ctx, cfg, args[1:], stdout)
	default:
		return fmt.Errorf("user: unknown command %q", args[0])
	}
}

func userCreate(ctx context.Context, cfg config.Config, args []string, stdin io.Reader, stdout, stderr io.Writer) error {
	flags := flag.NewFlagSet("user create", flag.ContinueOnError)
	flags.SetOutput(io.Discard)

	name := flags.String("name", "", "display name")
	email := flags.String("email", "", "sign-in address")
	admin := flags.Bool("admin", false, "grant administrator rights")

	if err := flags.Parse(args); err != nil {
		return fmt.Errorf("user create: %w", err)
	}
	if flags.NArg() > 0 {
		return fmt.Errorf("user create: unexpected argument %q", flags.Arg(0))
	}
	if *name == "" || *email == "" {
		return errors.New("user create: --name and --email are required")
	}

	password, err := readPassword(stdin, stderr)
	if err != nil {
		return err
	}

	db, accounts, err := openAccounts(cfg)
	if err != nil {
		return err
	}
	defer closeDatabase(ctx, db)

	user, err := accounts.CreateUser(ctx, auth.CreateUserInput{
		Name:     *name,
		Email:    *email,
		Password: password,
		IsAdmin:  *admin,
	})
	if err != nil {
		return fmt.Errorf("user create: %w", err)
	}

	role := "member"
	if user.IsAdmin {
		role = "administrator"
	}
	_, err = fmt.Fprintf(stdout, "created %s (%s) as %s\n", user.Email, user.Name, role)
	return err
}

func userPassword(ctx context.Context, cfg config.Config, args []string, stdout io.Writer) error {
	if len(args) != 1 {
		return errors.New("user password: expected one email")
	}

	db, accounts, err := openAccounts(cfg)
	if err != nil {
		return err
	}
	defer closeDatabase(ctx, db)

	token, err := accounts.CreateResetToken(ctx, args[0])
	if err != nil {
		return fmt.Errorf("user password: %w", err)
	}

	_, err = fmt.Fprintln(stdout, publicURL(cfg)+"/password-reset/"+token.Secret)
	return err
}

func openAccounts(cfg config.Config) (*store.Store, *auth.Service, error) {
	db, err := store.Open(cfg.DataDir)
	if err != nil {
		return nil, nil, err
	}
	return db, auth.New(auth.Options{Store: db, SessionTTL: cfg.SessionTTL}), nil
}

func closeDatabase(ctx context.Context, db *store.Store) {
	if err := db.Close(); err != nil {
		slog.Default().LogAttrs(ctx, slog.LevelError, "close database", slog.String("error", err.Error()))
	}
}

// readPassword reads one line from stdin without echoing it when stdin is a
// terminal. A piped password keeps scripting working.
func readPassword(stdin io.Reader, stderr io.Writer) (string, error) {
	if file, ok := stdin.(*os.File); ok && term.IsTerminal(int(file.Fd())) {
		if _, err := fmt.Fprint(stderr, "Password: "); err != nil {
			return "", err
		}
		password, err := term.ReadPassword(int(file.Fd()))
		_, _ = fmt.Fprintln(stderr)
		if err != nil {
			return "", fmt.Errorf("read password: %w", err)
		}
		return string(password), nil
	}

	line, err := bufio.NewReader(stdin).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return "", fmt.Errorf("read password: %w", err)
	}
	return strings.TrimRight(line, "\r\n"), nil
}

// publicURL is the address a person types: FRESHGUARD_BASE_URL when set, else
// the listen address with a wildcard host replaced by localhost.
func publicURL(cfg config.Config) string {
	if cfg.BaseURL != "" {
		return cfg.BaseURL
	}

	scheme := schemeHTTP
	addr := cfg.Addr
	if rest, ok := strings.CutPrefix(addr, schemeHTTPS); ok {
		scheme, addr = schemeHTTPS, rest
	} else if rest, ok := strings.CutPrefix(addr, schemeHTTP); ok {
		addr = rest
	}

	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return scheme + addr
	}

	switch host {
	case "", "0.0.0.0", "::":
		host = "localhost"
	}

	return scheme + net.JoinHostPort(host, port)
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
	// The container probe dials the local listener; TLS, if any, terminates at
	// the reverse proxy in front of it, and an https:// address is honoured below.
	scheme := schemeHTTP
	addr = strings.TrimRight(addr, "/")
	if rest, ok := strings.CutPrefix(addr, schemeHTTPS); ok {
		scheme, addr = schemeHTTPS, rest
	} else if rest, ok := strings.CutPrefix(addr, schemeHTTP); ok {
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
