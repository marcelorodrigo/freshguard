package config

import (
	"log/slog"
	"testing"
	"time"
)

func env(values map[string]string) func(string) string {
	return func(key string) string { return values[key] }
}

func TestLoadDefaults(t *testing.T) {
	t.Parallel()

	cfg, err := Load(env(nil))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}

	if cfg.Addr != DefaultAddr {
		t.Errorf("Addr = %q, want %q", cfg.Addr, DefaultAddr)
	}
	if cfg.DataDir != DefaultDataDir {
		t.Errorf("DataDir = %q, want %q", cfg.DataDir, DefaultDataDir)
	}
	if cfg.BaseURL != "" {
		t.Errorf("BaseURL = %q, want empty", cfg.BaseURL)
	}
	if cfg.LogLevel != slog.LevelInfo {
		t.Errorf("LogLevel = %v, want info", cfg.LogLevel)
	}
	if cfg.SessionTTL != DefaultSessionTTL {
		t.Errorf("SessionTTL = %v, want %v", cfg.SessionTTL, DefaultSessionTTL)
	}
	if cfg.OFFBaseURL != DefaultOFFBaseURL {
		t.Errorf("OFFBaseURL = %q, want %q", cfg.OFFBaseURL, DefaultOFFBaseURL)
	}
	if cfg.TZ != time.Local {
		t.Errorf("TZ = %v, want the system zone", cfg.TZ)
	}
}

func TestLoadOverrides(t *testing.T) {
	t.Parallel()

	cfg, err := Load(env(map[string]string{
		"FRESHGUARD_ADDR":         "127.0.0.1:9000",
		"FRESHGUARD_DATA_DIR":     "/var/lib/freshguard",
		"FRESHGUARD_BASE_URL":     "https://guard.example.com/",
		"FRESHGUARD_LOG_LEVEL":    "DEBUG",
		"FRESHGUARD_SESSION_TTL":  "48h",
		"FRESHGUARD_OFF_BASE_URL": "http://off.test",
		"FRESHGUARD_TZ":           "America/Sao_Paulo",
	}))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}

	if cfg.Addr != "127.0.0.1:9000" {
		t.Errorf("Addr = %q", cfg.Addr)
	}
	if cfg.DataDir != "/var/lib/freshguard" {
		t.Errorf("DataDir = %q", cfg.DataDir)
	}
	if cfg.BaseURL != "https://guard.example.com" {
		t.Errorf("BaseURL = %q, want the trailing slash trimmed", cfg.BaseURL)
	}
	if cfg.LogLevel != slog.LevelDebug {
		t.Errorf("LogLevel = %v, want debug", cfg.LogLevel)
	}
	if cfg.SessionTTL != 48*time.Hour {
		t.Errorf("SessionTTL = %v, want 48h", cfg.SessionTTL)
	}
	if cfg.OFFBaseURL != "http://off.test" {
		t.Errorf("OFFBaseURL = %q", cfg.OFFBaseURL)
	}
	if cfg.TZ == nil || cfg.TZ.String() != "America/Sao_Paulo" {
		t.Errorf("TZ = %v, want America/Sao_Paulo", cfg.TZ)
	}
}

func TestLoadRejectsInvalidValues(t *testing.T) {
	t.Parallel()

	tests := map[string]struct {
		key   string
		value string
	}{
		"unknown log level": {"FRESHGUARD_LOG_LEVEL", "verbose"},
		"unparsable ttl":    {"FRESHGUARD_SESSION_TTL", "thirty days"},
		"zero ttl":          {"FRESHGUARD_SESSION_TTL", "0s"},
		"negative ttl":      {"FRESHGUARD_SESSION_TTL", "-1h"},
		"unknown zone":      {"FRESHGUARD_TZ", "Mars/Olympus"},
	}

	for name, test := range tests {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			if _, err := Load(env(map[string]string{test.key: test.value})); err == nil {
				t.Fatalf("Load(%s=%q) = nil error, want failure", test.key, test.value)
			}
		})
	}
}
