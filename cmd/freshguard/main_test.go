package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
	"github.com/marcelorodrigo/freshguard/internal/config"
	"github.com/marcelorodrigo/freshguard/internal/store"
)

const testPassword = "correct horse battery staple"

// envMap turns a map into the getenv signature config.Load expects.
func envMap(values map[string]string) func(string) string {
	return func(key string) string { return values[key] }
}

func runCommand(t *testing.T, args []string, stdin string, getenv func(string) string) (stdout, stderr string, err error) {
	t.Helper()

	var out, errOut bytes.Buffer
	err = run(context.Background(), args, strings.NewReader(stdin), &out, &errOut, getenv)
	return out.String(), errOut.String(), err
}

func TestRunVersion(t *testing.T) {
	t.Parallel()

	stdout, _, err := runCommand(t, []string{"version"}, "", envMap(nil))
	if err != nil {
		t.Fatalf("run(version): %v", err)
	}
	if strings.TrimSpace(stdout) == "" {
		t.Fatal("version printed nothing")
	}
}

func TestRunHelp(t *testing.T) {
	t.Parallel()

	stdout, _, err := runCommand(t, []string{"help"}, "", envMap(nil))
	if err != nil {
		t.Fatalf("run(help): %v", err)
	}
	if !strings.Contains(stdout, "freshguard healthcheck") {
		t.Errorf("help text = %q, want the healthcheck subcommand", stdout)
	}
	if !strings.Contains(stdout, "freshguard user create") {
		t.Errorf("help text = %q, want the user subcommands", stdout)
	}
}

func TestRunVersionAndHelpIgnoreBrokenConfig(t *testing.T) {
	t.Parallel()

	broken := envMap(map[string]string{"FRESHGUARD_LOG_LEVEL": "verbose"})

	for _, command := range []string{"version", "help"} {
		if _, _, err := runCommand(t, []string{command}, "", broken); err != nil {
			t.Errorf("run(%s) with broken config = %v, want success", command, err)
		}
	}
}

func TestRunServeValidatesConfig(t *testing.T) {
	t.Parallel()

	broken := envMap(map[string]string{"FRESHGUARD_LOG_LEVEL": "verbose"})

	if _, _, err := runCommand(t, []string{"serve"}, "", broken); err == nil {
		t.Fatal("run(serve) with broken config = nil error, want the config failure")
	}
}

func TestRunUnknownCommand(t *testing.T) {
	t.Parallel()

	_, _, err := runCommand(t, []string{"migrate"}, "", envMap(nil))
	if err == nil {
		t.Fatal("run(migrate) = nil error, want failure")
	}
	if !strings.Contains(err.Error(), "migrate") {
		t.Errorf("error = %v, want the unknown command named", err)
	}
}

func TestUserCreateAndResetLink(t *testing.T) {
	t.Parallel()

	dataDir := t.TempDir()
	env := envMap(map[string]string{
		"FRESHGUARD_DATA_DIR": dataDir,
		"FRESHGUARD_BASE_URL": "https://fresh.example",
	})

	stdout, _, err := runCommand(t, []string{
		"user", "create", "--name", "Marcelo", "--email", "m@example.com",
	}, testPassword+"\n", env)
	if err != nil {
		t.Fatalf("user create: %v", err)
	}
	if !strings.Contains(stdout, "m@example.com") {
		t.Errorf("user create output = %q, want the address", stdout)
	}
	if !strings.Contains(stdout, "administrator") {
		t.Errorf("user create output = %q, want the first account promoted to administrator", stdout)
	}

	stdout, _, err = runCommand(t, []string{"user", "password", "m@example.com"}, "", env)
	if err != nil {
		t.Fatalf("user password: %v", err)
	}

	const prefix = "https://fresh.example/password-reset/"
	link := strings.TrimSpace(stdout)
	if !strings.HasPrefix(link, prefix) {
		t.Fatalf("reset link = %q, want the %s prefix", link, prefix)
	}

	// The printed link must be a token the server accepts.
	db, err := store.Open(dataDir)
	if err != nil {
		t.Fatalf("store.Open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })

	accounts := auth.New(auth.Options{Store: db})
	email, err := accounts.ResetTokenEmail(t.Context(), strings.TrimPrefix(link, prefix))
	if err != nil {
		t.Fatalf("ResetTokenEmail: %v", err)
	}
	if email != "m@example.com" {
		t.Errorf("token email = %q, want m@example.com", email)
	}
}

func TestUserPasswordUnknownEmail(t *testing.T) {
	t.Parallel()

	env := envMap(map[string]string{"FRESHGUARD_DATA_DIR": t.TempDir()})

	_, _, err := runCommand(t, []string{"user", "password", "nobody@example.com"}, "", env)
	if !errors.Is(err, auth.ErrUserNotFound) {
		t.Errorf("user password(unknown) = %v, want ErrUserNotFound", err)
	}
}

func TestUserCommandsReportAStoreFailure(t *testing.T) {
	t.Parallel()

	path := filepath.Join(t.TempDir(), "not-a-directory")
	if err := os.WriteFile(path, []byte("x"), 0o600); err != nil {
		t.Fatalf("write file: %v", err)
	}

	env := envMap(map[string]string{"FRESHGUARD_DATA_DIR": path})

	if _, _, err := runCommand(t, []string{"user", "password", "m@example.com"}, "", env); err == nil {
		t.Error("user password with a file as the data directory = nil error, want failure")
	}
	if _, _, err := runCommand(t, []string{
		"user", "create", "--name", "Marcelo", "--email", "m@example.com",
	}, testPassword+"\n", env); err == nil {
		t.Error("user create with a file as the data directory = nil error, want failure")
	}
}

func TestUserCreateRejectsBadInput(t *testing.T) {
	t.Parallel()

	env := envMap(map[string]string{"FRESHGUARD_DATA_DIR": t.TempDir()})

	tests := map[string]struct {
		args  []string
		stdin string
	}{
		"missing flags":   {args: []string{"user", "create"}},
		"missing email":   {args: []string{"user", "create", "--name", "Marcelo"}},
		"unknown flag":    {args: []string{"user", "create", "--name", "Marcelo", "--email", "m@example.com", "--root"}},
		"short password":  {args: []string{"user", "create", "--name", "Marcelo", "--email", "m@example.com"}, stdin: "short\n"},
		"empty password":  {args: []string{"user", "create", "--name", "Marcelo", "--email", "m@example.com"}, stdin: ""},
		"no user command": {args: []string{"user"}},
		"no reset email":  {args: []string{"user", "password"}},
		"unknown user op": {args: []string{"user", "promote", "m@example.com"}},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			if _, _, err := runCommand(t, tc.args, tc.stdin, env); err == nil {
				t.Errorf("args %v = nil error, want failure", tc.args)
			}
		})
	}
}

func TestUserCreateRejectsDuplicateEmail(t *testing.T) {
	t.Parallel()

	env := envMap(map[string]string{"FRESHGUARD_DATA_DIR": t.TempDir()})
	args := []string{"user", "create", "--name", "Marcelo", "--email", "m@example.com"}

	if _, _, err := runCommand(t, args, testPassword+"\n", env); err != nil {
		t.Fatalf("first user create: %v", err)
	}

	_, _, err := runCommand(t, args, testPassword+"\n", env)
	if err == nil {
		t.Fatal("duplicate user create = nil error, want failure")
	}
	if !strings.Contains(err.Error(), "unique") {
		t.Errorf("error = %v, want the unique rule", err)
	}
}

// TestRunServerServesHealthAndShutsDown exercises the wiring serve and main
// rely on: store, auth, router, listener and graceful shutdown.
func TestRunServerServesHealthAndShutsDown(t *testing.T) {
	t.Parallel()

	cfg := config.Config{
		Addr:       "127.0.0.1:0",
		DataDir:    t.TempDir(),
		LogLevel:   slog.LevelInfo,
		SessionTTL: time.Hour,
		TZ:         time.UTC,
	}

	listener, err := net.Listen("tcp", cfg.Addr)
	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() {
		done <- runServer(ctx, cfg, slog.New(slog.NewTextHandler(io.Discard, nil)), listener)
	}()

	target := "http://" + listener.Addr().String() + "/api/v1/health"
	body := waitForHealth(t, target)
	if !strings.Contains(body, `"setup_required":true`) {
		t.Errorf("health = %s, want setup_required true on a fresh directory", body)
	}

	cancel()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("runServer: %v", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("runServer did not shut down")
	}
}

func waitForHealth(t *testing.T, target string) string {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		response, err := http.Get(target) // NOSONAR: the test listener is plain HTTP on loopback.
		if err == nil {
			body, readErr := io.ReadAll(response.Body)
			_ = response.Body.Close()
			if readErr != nil {
				t.Fatalf("read health: %v", readErr)
			}
			if response.StatusCode == http.StatusOK {
				return string(body)
			}
		}
		time.Sleep(5 * time.Millisecond)
	}

	t.Fatalf("no healthy response from %s", target)
	return ""
}

func TestPublicURL(t *testing.T) {
	t.Parallel()

	tests := map[string]struct {
		cfg  config.Config
		want string
	}{
		"default port":  {cfg: config.Config{Addr: ":8080"}, want: "http://localhost:8080"},
		"wildcard v4":   {cfg: config.Config{Addr: "0.0.0.0:9000"}, want: "http://localhost:9000"},
		"wildcard v6":   {cfg: config.Config{Addr: "[::]:9000"}, want: "http://localhost:9000"},
		"loopback":      {cfg: config.Config{Addr: "127.0.0.1:8080"}, want: "http://127.0.0.1:8080"},
		"named host":    {cfg: config.Config{Addr: "fresh.example"}, want: "http://fresh.example"},
		"https listen":  {cfg: config.Config{Addr: "https://fresh.example"}, want: "https://fresh.example"},
		"base URL wins": {cfg: config.Config{Addr: ":8080", BaseURL: "https://inventory.example"}, want: "https://inventory.example"},
	}

	for name, tc := range tests {
		if got := publicURL(tc.cfg); got != tc.want {
			t.Errorf("%s: publicURL = %q, want %q", name, got, tc.want)
		}
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
		":8080":             "http://127.0.0.1:8080/api/v1/health",
		"127.0.0.1:9000":    "http://127.0.0.1:9000/api/v1/health",
		"0.0.0.0:9000":      "http://127.0.0.1:9000/api/v1/health",
		"[::]:9000":         "http://[::1]:9000/api/v1/health",
		"http://app:8080":   "http://app:8080/api/v1/health",
		"https://app.test":  "https://app.test/api/v1/health",
		"https://app.test/": "https://app.test/api/v1/health",
		"http://[::1]:8080": "http://[::1]:8080/api/v1/health",
		"app.test":          "http://app.test/api/v1/health",
	}

	for addr, want := range tests {
		if got := healthURL(addr); got != want {
			t.Errorf("healthURL(%q) = %q, want %q", addr, got, want)
		}
	}
}
