package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
)

// Login checks the credentials and opens a session. An unknown email costs the
// same password hashing as a known one, so timing does not enumerate accounts.
func (s *Service) Login(ctx context.Context, email, password, userAgent string) (NewSession, error) {
	user, hash, err := s.userWithHashByEmail(ctx, strings.TrimSpace(email))
	if errors.Is(err, sql.ErrNoRows) {
		_, _ = VerifyPassword(password, timingEqualizerHash)
		return NewSession{}, ErrInvalidCredentials
	}
	if err != nil {
		return NewSession{}, err
	}

	ok, err := VerifyPassword(password, hash)
	if err != nil {
		return NewSession{}, fmt.Errorf("verify password for user %s: %w", user.ID, err)
	}
	if !ok {
		return NewSession{}, ErrInvalidCredentials
	}

	secret, sessionID, err := newSessionSecret()
	if err != nil {
		return NewSession{}, err
	}

	now := s.now().UTC()
	expiresAt := now.Add(s.ttl)

	if _, err := s.db.Writer().ExecContext(ctx, `INSERT INTO sessions
		(id, user_id, user_agent, created_at, last_seen_at, expires_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		sessionID, user.ID, truncate(userAgent, userAgentMaxLength),
		formatTime(now), formatTime(now), formatTime(expiresAt)); err != nil {
		return NewSession{}, fmt.Errorf("insert session: %w", err)
	}

	return NewSession{User: user, Secret: secret, ExpiresAt: expiresAt}, nil
}

// Session resolves a cookie secret into its user. An expired session is
// deleted; a session older than SessionRefreshInterval is slid forward and
// reports Extended.
func (s *Service) Session(ctx context.Context, secret string) (Session, error) {
	if secret == "" {
		return Session{}, ErrSessionInvalid
	}

	now := s.now().UTC()
	sessionID := hashToken(secret)

	var (
		lastSeen  string
		expiresAt string
	)
	user, err := scanUser(s.db.Reader().QueryRowContext(ctx, `SELECT
			u.id, u.name, u.email, u.is_admin, u.must_reset, u.created_at, u.updated_at,
			s.last_seen_at, s.expires_at
		FROM sessions s
		JOIN users u ON u.id = s.user_id
		WHERE s.id = ?`, sessionID), &lastSeen, &expiresAt)
	if errors.Is(err, sql.ErrNoRows) {
		return Session{}, ErrSessionInvalid
	}
	if err != nil {
		return Session{}, fmt.Errorf("read session: %w", err)
	}

	expiry, err := parseTime(expiresAt)
	if err != nil {
		return Session{}, err
	}
	if !now.Before(expiry) {
		if _, err := s.db.Writer().ExecContext(ctx, "DELETE FROM sessions WHERE id = ?", sessionID); err != nil {
			return Session{}, fmt.Errorf("delete expired session: %w", err)
		}
		return Session{}, ErrSessionInvalid
	}

	session := Session{User: user, ExpiresAt: expiry}

	seen, err := parseTime(lastSeen)
	if err != nil {
		return Session{}, err
	}
	if now.Sub(seen) < SessionRefreshInterval {
		return session, nil
	}

	slid := now.Add(s.ttl)
	if _, err := s.db.Writer().ExecContext(ctx, `UPDATE sessions
		SET last_seen_at = ?, expires_at = ? WHERE id = ?`,
		formatTime(now), formatTime(slid), sessionID); err != nil {
		return Session{}, fmt.Errorf("refresh session: %w", err)
	}

	session.ExpiresAt = slid
	session.Extended = true
	return session, nil
}

// RevokeSession deletes the session behind secret. Logging out twice is not an
// error.
func (s *Service) RevokeSession(ctx context.Context, secret string) error {
	if secret == "" {
		return nil
	}
	if _, err := s.db.Writer().ExecContext(ctx, "DELETE FROM sessions WHERE id = ?", hashToken(secret)); err != nil {
		return fmt.Errorf("delete session: %w", err)
	}
	return nil
}

func newSessionSecret() (secret, id string, err error) {
	return newSecret(sessionSecretBytes)
}
