// Package auth owns accounts, passwords and sessions.
//
// It runs on the database and returns rule errors; it never imports net/http.
// The HTTP layer maps an error to the envelope described in docs/api.md: a
// FieldError becomes 422 with a rule token, a sentinel becomes a status.
package auth

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"net/mail"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/marcelorodrigo/freshguard/internal/store"
)

const (
	// DefaultSessionTTL is the sliding lifetime of a session when Options
	// leaves it zero: docs/architecture.md fixes it at 30 days.
	DefaultSessionTTL = 30 * 24 * time.Hour
	// SessionRefreshInterval is how often a session is slid forward. Without
	// it every authenticated request would write to the database.
	SessionRefreshInterval = time.Hour
	// PasswordResetTTL is how long a link printed by the CLI stays valid.
	PasswordResetTTL = time.Hour

	// MinPasswordLength and MaxPasswordLength bound every password, in runes.
	MinPasswordLength = 8
	MaxPasswordLength = 255
	// MaxNameLength and MaxEmailLength bound the profile fields, in runes.
	MaxNameLength  = 255
	MaxEmailLength = 255

	// sessionSecretBytes is 256 bits of entropy, base64url-encoded in the cookie.
	sessionSecretBytes = 32
	// resetTokenBytes is the same 256 bits, for the link the CLI prints.
	resetTokenBytes = 32
	// userAgentMaxLength keeps a hostile header out of the database.
	userAgentMaxLength = 512

	timeLayout = time.RFC3339
)

// Errors the HTTP layer maps to statuses. Validation failures use FieldError.
var (
	// ErrInvalidCredentials is the single answer to an unknown email and to a
	// wrong password, so the response cannot enumerate accounts.
	ErrInvalidCredentials = errors.New("auth: invalid credentials")
	// ErrSetupComplete is returned by FirstAdmin once an account exists.
	ErrSetupComplete = errors.New("auth: setup already complete")
	// ErrSessionInvalid is returned for an unknown, revoked or expired secret.
	ErrSessionInvalid = errors.New("auth: invalid session")
	// ErrUserNotFound is returned for a lookup that must name an account.
	ErrUserNotFound = errors.New("auth: user not found")
	// ErrResetTokenInvalid is returned for an unknown, used or expired link.
	ErrResetTokenInvalid = errors.New("auth: invalid password reset token")
)

// FieldError is a validation failure expressed as the rule token the API
// returns, for example Field: "email", Rule: "unique".
type FieldError struct {
	Field string
	Rule  string
}

func (e *FieldError) Error() string { return "auth: " + e.Field + ": " + e.Rule }

func fieldError(field, rule string) error { return &FieldError{Field: field, Rule: rule} }

// User is an account without its password hash.
type User struct {
	ID        string
	Name      string
	Email     string
	IsAdmin   bool
	MustReset bool
	CreatedAt time.Time
	UpdatedAt time.Time
}

// Session is a signed-in user and the lifetime of the cookie that names the
// session. Extended reports that this lookup slid the expiry, so the caller
// should re-issue the cookie.
type Session struct {
	User      User
	ExpiresAt time.Time
	Extended  bool
}

// NewSession is a session that was just created, with the one copy of the
// secret the browser is allowed to see.
type NewSession struct {
	User      User
	Secret    string
	ExpiresAt time.Time
}

// ResetToken is a freshly minted single-use password reset link.
type ResetToken struct {
	Secret    string
	ExpiresAt time.Time
}

// CreateUserInput is the payload of setup, the CLI's user creation and the
// admin user form.
type CreateUserInput struct {
	Name     string
	Email    string
	Password string
	IsAdmin  bool
}

// ProfilePatch is a subset of the fields PATCH /me may change. A nil field is
// left alone.
type ProfilePatch struct {
	Name            *string
	Email           *string
	Password        *string
	CurrentPassword *string
}

// Options configures a Service.
type Options struct {
	// Store is the open database. Required.
	Store *store.Store
	// SessionTTL is the sliding session lifetime; zero uses DefaultSessionTTL.
	SessionTTL time.Duration
	// Now overrides the clock in tests; nil uses time.Now.
	Now func() time.Time
}

// Service is the account and session logic.
type Service struct {
	db  *store.Store
	ttl time.Duration
	now func() time.Time
}

// New builds the service. It panics without a Store: that is a wiring bug, not
// a runtime condition the server could recover from.
func New(opts Options) *Service {
	if opts.Store == nil {
		panic("auth: New requires Options.Store")
	}
	if opts.SessionTTL <= 0 {
		opts.SessionTTL = DefaultSessionTTL
	}
	if opts.Now == nil {
		opts.Now = time.Now
	}

	return &Service{db: opts.Store, ttl: opts.SessionTTL, now: opts.Now}
}

// SetupRequired reports whether the household has no accounts yet.
func (s *Service) SetupRequired(ctx context.Context) (bool, error) {
	count, err := s.UserCount(ctx)
	return count == 0, err
}

// UserCount returns how many accounts exist.
func (s *Service) UserCount(ctx context.Context) (int, error) {
	var count int
	if err := s.db.Reader().QueryRowContext(ctx, "SELECT COUNT(*) FROM users").Scan(&count); err != nil {
		return 0, fmt.Errorf("count users: %w", err)
	}
	return count, nil
}

// ValidateName checks a display name.
func ValidateName(name string) error {
	switch {
	case strings.TrimSpace(name) == "":
		return fieldError("name", "required")
	case utf8.RuneCountInString(name) > MaxNameLength:
		return fieldError("name", "max")
	default:
		return nil
	}
}

// ValidateEmail checks an email address.
func ValidateEmail(email string) error {
	switch {
	case email == "":
		return fieldError("email", "required")
	case utf8.RuneCountInString(email) > MaxEmailLength:
		return fieldError("email", "max")
	case !ValidEmail(email):
		return fieldError("email", "email")
	default:
		return nil
	}
}

// ValidEmail reports whether address is a bare mailbox, not a display name
// with an address inside it.
func ValidEmail(address string) bool {
	parsed, err := mail.ParseAddress(address)
	return err == nil && parsed.Name == "" && parsed.Address == address
}

// ValidatePassword checks the length rule every password shares.
func ValidatePassword(password string) error {
	switch length := utf8.RuneCountInString(password); {
	case length < MinPasswordLength:
		return fieldError("password", "min")
	case length > MaxPasswordLength:
		return fieldError("password", "max")
	default:
		return nil
	}
}

// newSecret returns the value the cookie or link carries and the SHA-256 the
// database stores in its place.
func newSecret(size int) (secret, id string, err error) {
	buf := make([]byte, size)
	if _, err := rand.Read(buf); err != nil {
		return "", "", fmt.Errorf("generate secret: %w", err)
	}

	secret = base64.RawURLEncoding.EncodeToString(buf)
	return secret, hashToken(secret), nil
}

func hashToken(value string) string {
	sum := sha256.Sum256([]byte(value))
	return hex.EncodeToString(sum[:])
}

func formatTime(t time.Time) string {
	return t.UTC().Format(timeLayout)
}

func parseTime(value string) (time.Time, error) {
	parsed, err := time.Parse(timeLayout, value)
	if err != nil {
		return time.Time{}, fmt.Errorf("parse timestamp %q: %w", value, err)
	}
	return parsed.UTC(), nil
}

func truncate(value string, limit int) string {
	if len(value) <= limit {
		return value
	}
	return value[:limit]
}
