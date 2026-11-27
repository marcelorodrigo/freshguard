package main

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

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
