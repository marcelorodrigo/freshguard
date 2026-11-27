// Package config turns the environment into the settings the server needs.
//
// Every setting is optional: an empty directory with no environment starts a
// working server. Load validates the values it reads and reports the offending
// variable, so a typo fails on start rather than at request time.
package config

import (
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"time"
)

const (
	// DefaultAddr is the listen address when FRESHGUARD_ADDR is unset.
	DefaultAddr = ":8080"
	// DefaultDataDir is where freshguard.db lives when FRESHGUARD_DATA_DIR is unset.
	DefaultDataDir = "./data"
	// DefaultLogLevel is the slog level when FRESHGUARD_LOG_LEVEL is unset.
	DefaultLogLevel = "info"
	// DefaultSessionTTL is the sliding session lifetime when FRESHGUARD_SESSION_TTL is unset.
	DefaultSessionTTL = 720 * time.Hour
	// DefaultOFFBaseURL is the Open Food Facts endpoint when FRESHGUARD_OFF_BASE_URL is unset.
	DefaultOFFBaseURL = "https://world.openfoodfacts.org"
)

// Config is the resolved server configuration.
type Config struct {
	// Addr is the TCP address the server listens on, for example ":8080".
	Addr string
	// DataDir holds freshguard.db and its WAL files.
	DataDir string
	// BaseURL is the absolute URL used in links printed by the CLI. Empty when unset.
	BaseURL string
	// LogLevel is the minimum level slog emits.
	LogLevel slog.Level
	// SessionTTL is the sliding session lifetime.
	SessionTTL time.Duration
	// OFFBaseURL is the Open Food Facts base URL used by the barcode client.
	OFFBaseURL string
	// TZ is the zone used by date-only logic and log timestamps.
	TZ *time.Location
}

// Load resolves the configuration from getenv, applying defaults for anything
// unset. It returns an error naming the variable that could not be parsed.
func Load(getenv func(string) string) (Config, error) {
	cfg := Config{
		Addr:       envOr(getenv, "FRESHGUARD_ADDR", DefaultAddr),
		DataDir:    envOr(getenv, "FRESHGUARD_DATA_DIR", DefaultDataDir),
		BaseURL:    strings.TrimRight(getenv("FRESHGUARD_BASE_URL"), "/"),
		SessionTTL: DefaultSessionTTL,
		OFFBaseURL: envOr(getenv, "FRESHGUARD_OFF_BASE_URL", DefaultOFFBaseURL),
		TZ:         time.Local,
	}

	level, err := parseLevel(envOr(getenv, "FRESHGUARD_LOG_LEVEL", DefaultLogLevel))
	if err != nil {
		return Config{}, err
	}
	cfg.LogLevel = level

	if raw := getenv("FRESHGUARD_SESSION_TTL"); raw != "" {
		ttl, err := time.ParseDuration(raw)
		if err != nil {
			return Config{}, fmt.Errorf("FRESHGUARD_SESSION_TTL: %w", err)
		}
		if ttl <= 0 {
			return Config{}, errors.New("FRESHGUARD_SESSION_TTL: must be positive")
		}
		cfg.SessionTTL = ttl
	}

	if raw := getenv("FRESHGUARD_TZ"); raw != "" {
		loc, err := time.LoadLocation(raw)
		if err != nil {
			return Config{}, fmt.Errorf("FRESHGUARD_TZ: %w", err)
		}
		cfg.TZ = loc
	}

	return cfg, nil
}

func envOr(getenv func(string) string, key, fallback string) string {
	if value := getenv(key); value != "" {
		return value
	}
	return fallback
}

func parseLevel(raw string) (slog.Level, error) {
	switch strings.ToLower(raw) {
	case "debug":
		return slog.LevelDebug, nil
	case "info":
		return slog.LevelInfo, nil
	case "warn":
		return slog.LevelWarn, nil
	case "error":
		return slog.LevelError, nil
	default:
		return slog.LevelInfo, fmt.Errorf("FRESHGUARD_LOG_LEVEL: unknown level %q", raw)
	}
}
