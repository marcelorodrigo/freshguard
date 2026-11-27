package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"

	"github.com/marcelorodrigo/freshguard/internal/ids"
	"github.com/marcelorodrigo/freshguard/internal/store"
)

// FirstAdmin creates the setup administrator and signs them in. It is the only
// write that is allowed while the household is empty, and it refuses to run
// twice.
func (s *Service) FirstAdmin(ctx context.Context, in CreateUserInput) (NewSession, error) {
	// setup is unauthenticated, so a request that cannot succeed must not be
	// allowed to cost a 64 MiB password hash. The transaction checks again.
	required, err := s.SetupRequired(ctx)
	if err != nil {
		return NewSession{}, err
	}
	if !required {
		return NewSession{}, ErrSetupComplete
	}

	in.Name = strings.TrimSpace(in.Name)
	in.Email = strings.TrimSpace(in.Email)
	in.IsAdmin = true

	if err := validateNewUser(in); err != nil {
		return NewSession{}, err
	}

	hash, err := HashPassword(in.Password)
	if err != nil {
		return NewSession{}, err
	}

	now := s.now().UTC()
	user := User{
		ID:        ids.New(),
		Name:      in.Name,
		Email:     in.Email,
		IsAdmin:   true,
		CreatedAt: now,
		UpdatedAt: now,
	}
	secret, sessionID, err := newSessionSecret()
	if err != nil {
		return NewSession{}, err
	}
	expiresAt := now.Add(s.ttl)

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return NewSession{}, fmt.Errorf("begin setup: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var count int
	if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM users").Scan(&count); err != nil {
		return NewSession{}, fmt.Errorf("count users during setup: %w", err)
	}
	if count > 0 {
		return NewSession{}, ErrSetupComplete
	}

	if _, err := tx.ExecContext(ctx, `INSERT INTO users
		(id, name, email, password_hash, is_admin, must_reset, created_at, updated_at)
		VALUES (?, ?, ?, ?, 1, 0, ?, ?)`,
		user.ID, user.Name, user.Email, hash, formatTime(now), formatTime(now)); err != nil {
		if store.IsUniqueViolation(err) {
			return NewSession{}, fieldError("email", "unique")
		}
		return NewSession{}, fmt.Errorf("insert first admin: %w", err)
	}

	if _, err := tx.ExecContext(ctx, `INSERT INTO sessions
		(id, user_id, user_agent, created_at, last_seen_at, expires_at)
		VALUES (?, ?, NULL, ?, ?, ?)`,
		sessionID, user.ID, formatTime(now), formatTime(now), formatTime(expiresAt)); err != nil {
		return NewSession{}, fmt.Errorf("insert setup session: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return NewSession{}, fmt.Errorf("commit setup: %w", err)
	}

	return NewSession{User: user, Secret: secret, ExpiresAt: expiresAt}, nil
}

// CreateUser adds an account without signing it in. The CLI uses it when the
// setup screen is not an option; the admin endpoints use it too.
//
// The first account is always an administrator: a household with no admin
// cannot be administered, and the CLI must agree with setup on that.
func (s *Service) CreateUser(ctx context.Context, in CreateUserInput) (User, error) {
	in.Name = strings.TrimSpace(in.Name)
	in.Email = strings.TrimSpace(in.Email)

	if err := validateNewUser(in); err != nil {
		return User{}, err
	}

	hash, err := HashPassword(in.Password)
	if err != nil {
		return User{}, err
	}

	now := s.now().UTC()
	user := User{
		ID:        ids.New(),
		Name:      in.Name,
		Email:     in.Email,
		CreatedAt: now,
		UpdatedAt: now,
	}

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return User{}, fmt.Errorf("begin create user: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var count int
	if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM users").Scan(&count); err != nil {
		return User{}, fmt.Errorf("count users: %w", err)
	}
	user.IsAdmin = in.IsAdmin || count == 0

	if _, err := tx.ExecContext(ctx, `INSERT INTO users
		(id, name, email, password_hash, is_admin, must_reset, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, 0, ?, ?)`,
		user.ID, user.Name, user.Email, hash, user.IsAdmin, formatTime(now), formatTime(now)); err != nil {
		if store.IsUniqueViolation(err) {
			return User{}, fieldError("email", "unique")
		}
		return User{}, fmt.Errorf("insert user: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return User{}, fmt.Errorf("commit create user: %w", err)
	}

	return user, nil
}

func validateNewUser(in CreateUserInput) error {
	if err := ValidateName(in.Name); err != nil {
		return err
	}
	if err := ValidateEmail(in.Email); err != nil {
		return err
	}
	return ValidatePassword(in.Password)
}

// UpdateProfile applies a patch to one account. Setting a password requires
// the current one and clears must_reset.
func (s *Service) UpdateProfile(ctx context.Context, userID string, patch ProfilePatch) (User, error) {
	if patch.Name == nil && patch.Email == nil && patch.Password == nil {
		return s.userByID(ctx, userID)
	}

	user, hash, err := s.userWithHashByID(ctx, userID)
	if err != nil {
		return User{}, err
	}

	if patch.Name != nil {
		name := strings.TrimSpace(*patch.Name)
		if err := ValidateName(name); err != nil {
			return User{}, err
		}
		user.Name = name
	}

	if patch.Email != nil {
		email := strings.TrimSpace(*patch.Email)
		if err := ValidateEmail(email); err != nil {
			return User{}, err
		}
		user.Email = email
	}

	if patch.Password != nil {
		if err := ValidatePassword(*patch.Password); err != nil {
			return User{}, err
		}
		if patch.CurrentPassword == nil || *patch.CurrentPassword == "" {
			return User{}, fieldError("current_password", "required")
		}

		ok, err := VerifyPassword(*patch.CurrentPassword, hash)
		if err != nil {
			return User{}, fmt.Errorf("verify current password for user %s: %w", userID, err)
		}
		if !ok {
			return User{}, fieldError("current_password", "mismatch")
		}

		hash, err = HashPassword(*patch.Password)
		if err != nil {
			return User{}, err
		}
		user.MustReset = false
	}

	user.UpdatedAt = s.now().UTC()

	if _, err := s.db.Writer().ExecContext(ctx, `UPDATE users
		SET name = ?, email = ?, password_hash = ?, must_reset = ?, updated_at = ?
		WHERE id = ?`,
		user.Name, user.Email, hash, user.MustReset, formatTime(user.UpdatedAt), user.ID); err != nil {
		if store.IsUniqueViolation(err) {
			return User{}, fieldError("email", "unique")
		}
		return User{}, fmt.Errorf("update user %s: %w", user.ID, err)
	}

	return user, nil
}

// SetTemporaryPassword replaces a user's password with one they did not
// choose, flags must_reset and signs them out everywhere. An admin does this
// when a member is locked out.
func (s *Service) SetTemporaryPassword(ctx context.Context, userID, password string) error {
	if err := ValidatePassword(password); err != nil {
		return err
	}

	hash, err := HashPassword(password)
	if err != nil {
		return err
	}

	now := s.now().UTC()

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin temporary password: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	result, err := tx.ExecContext(ctx, `UPDATE users
		SET password_hash = ?, must_reset = 1, updated_at = ? WHERE id = ?`,
		hash, formatTime(now), userID)
	if err != nil {
		return fmt.Errorf("set temporary password: %w", err)
	}
	affected, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("count updated users: %w", err)
	}
	if affected == 0 {
		return ErrUserNotFound
	}

	if _, err := tx.ExecContext(ctx, "DELETE FROM sessions WHERE user_id = ?", userID); err != nil {
		return fmt.Errorf("revoke sessions: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit temporary password: %w", err)
	}
	return nil
}

// rowScanner is the part of *sql.Row and *sql.Rows this package scans with.
type rowScanner interface {
	Scan(dest ...any) error
}

// userColumns is the account shape without the hash; order matches scanUser.
const (
	userColumns = "id, name, email, is_admin, must_reset, created_at, updated_at"
	selectUsers = "SELECT " + userColumns
)

func scanUser(row rowScanner, extra ...any) (User, error) {
	var (
		user      User
		createdAt string
		updatedAt string
	)

	dest := []any{
		&user.ID, &user.Name, &user.Email, &user.IsAdmin, &user.MustReset,
		&createdAt, &updatedAt,
	}
	if err := row.Scan(append(dest, extra...)...); err != nil {
		return User{}, err
	}

	created, err := parseTime(createdAt)
	if err != nil {
		return User{}, fmt.Errorf("parse user created_at: %w", err)
	}
	updated, err := parseTime(updatedAt)
	if err != nil {
		return User{}, fmt.Errorf("parse user updated_at: %w", err)
	}
	user.CreatedAt, user.UpdatedAt = created, updated

	return user, nil
}

func (s *Service) userByID(ctx context.Context, userID string) (User, error) {
	user, err := scanUser(s.db.Reader().QueryRowContext(ctx,
		selectUsers+" FROM users WHERE id = ?", userID))
	if errors.Is(err, sql.ErrNoRows) {
		return User{}, ErrUserNotFound
	}
	if err != nil {
		return User{}, fmt.Errorf("read user %s: %w", userID, err)
	}
	return user, nil
}

func (s *Service) userByEmail(ctx context.Context, email string) (User, error) {
	user, err := scanUser(s.db.Reader().QueryRowContext(ctx,
		selectUsers+" FROM users WHERE email = ?", email))
	if errors.Is(err, sql.ErrNoRows) {
		return User{}, ErrUserNotFound
	}
	if err != nil {
		return User{}, fmt.Errorf("read user by email: %w", err)
	}
	return user, nil
}

func (s *Service) userWithHashByID(ctx context.Context, userID string) (User, string, error) {
	var hash string
	user, err := scanUser(s.db.Writer().QueryRowContext(ctx,
		selectUsers+", password_hash FROM users WHERE id = ?", userID), &hash)
	if errors.Is(err, sql.ErrNoRows) {
		return User{}, "", ErrUserNotFound
	}
	if err != nil {
		return User{}, "", fmt.Errorf("read user %s: %w", userID, err)
	}
	return user, hash, nil
}

func (s *Service) userWithHashByEmail(ctx context.Context, email string) (User, string, error) {
	var hash string
	user, err := scanUser(s.db.Writer().QueryRowContext(ctx,
		selectUsers+", password_hash FROM users WHERE email = ?", email), &hash)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return User{}, "", err
		}
		return User{}, "", fmt.Errorf("read user by email: %w", err)
	}
	return user, hash, nil
}
