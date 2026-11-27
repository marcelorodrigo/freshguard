package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
)

// CreateResetToken mints a single-use password reset link for email. Older
// unused links for the same account stop working.
func (s *Service) CreateResetToken(ctx context.Context, email string) (ResetToken, error) {
	user, err := s.userByEmail(ctx, strings.TrimSpace(email))
	if err != nil {
		return ResetToken{}, err
	}

	secret, tokenID, err := newResetSecret()
	if err != nil {
		return ResetToken{}, err
	}

	now := s.now().UTC()
	expiresAt := now.Add(PasswordResetTTL)

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return ResetToken{}, fmt.Errorf("begin reset token: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := tx.ExecContext(ctx,
		"DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL", user.ID); err != nil {
		return ResetToken{}, fmt.Errorf("clear reset tokens: %w", err)
	}

	if _, err := tx.ExecContext(ctx, `INSERT INTO password_resets
		(id, user_id, created_at, expires_at, used_at) VALUES (?, ?, ?, ?, NULL)`,
		tokenID, user.ID, formatTime(now), formatTime(expiresAt)); err != nil {
		return ResetToken{}, fmt.Errorf("insert reset token: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return ResetToken{}, fmt.Errorf("commit reset token: %w", err)
	}

	return ResetToken{Secret: secret, ExpiresAt: expiresAt}, nil
}

// ResetTokenEmail returns the address a valid link belongs to, or
// ErrResetTokenInvalid.
func (s *Service) ResetTokenEmail(ctx context.Context, token string) (string, error) {
	if token == "" {
		return "", ErrResetTokenInvalid
	}

	now := s.now().UTC()

	var email string
	err := s.db.Reader().QueryRowContext(ctx, `SELECT u.email
		FROM password_resets p
		JOIN users u ON u.id = p.user_id
		WHERE p.id = ? AND p.used_at IS NULL AND p.expires_at > ?`,
		hashToken(token), formatTime(now)).Scan(&email)
	if errors.Is(err, sql.ErrNoRows) {
		return "", ErrResetTokenInvalid
	}
	if err != nil {
		return "", fmt.Errorf("read reset token: %w", err)
	}
	return email, nil
}

// ResetPassword sets a new password from a valid link. It consumes the token,
// clears must_reset, drops the account's other links and signs the account out
// everywhere.
func (s *Service) ResetPassword(ctx context.Context, token, password string) error {
	if err := ValidatePassword(password); err != nil {
		return err
	}

	// The endpoint is unauthenticated: a link that cannot work must not be
	// worth a 64 MiB hash. The transaction re-checks before writing.
	if _, err := s.ResetTokenEmail(ctx, token); err != nil {
		return err
	}

	hash, err := HashPassword(password)
	if err != nil {
		return err
	}

	now := s.now().UTC()
	tokenID := hashToken(token)

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin password reset: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var userID string
	err = tx.QueryRowContext(ctx, `SELECT user_id FROM password_resets
		WHERE id = ? AND used_at IS NULL AND expires_at > ?`,
		tokenID, formatTime(now)).Scan(&userID)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrResetTokenInvalid
	}
	if err != nil {
		return fmt.Errorf("read reset token: %w", err)
	}

	if _, err := tx.ExecContext(ctx, `UPDATE users
		SET password_hash = ?, must_reset = 0, updated_at = ? WHERE id = ?`,
		hash, formatTime(now), userID); err != nil {
		return fmt.Errorf("set password from reset: %w", err)
	}

	if _, err := tx.ExecContext(ctx,
		"UPDATE password_resets SET used_at = ? WHERE id = ?", formatTime(now), tokenID); err != nil {
		return fmt.Errorf("consume reset token: %w", err)
	}

	if _, err := tx.ExecContext(ctx,
		"DELETE FROM password_resets WHERE user_id = ? AND id <> ?", userID, tokenID); err != nil {
		return fmt.Errorf("clear reset tokens: %w", err)
	}

	if _, err := tx.ExecContext(ctx, "DELETE FROM sessions WHERE user_id = ?", userID); err != nil {
		return fmt.Errorf("revoke sessions: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit password reset: %w", err)
	}
	return nil
}

func newResetSecret() (secret, id string, err error) {
	return newSecret(resetTokenBytes)
}
