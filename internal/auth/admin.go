package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/store"
)

// Admin rules ported from the Laravel UserPolicy: only an administrator may
// list, create, update or delete accounts; nobody deletes themselves; the
// last administrator survives; an administrator cannot take their own rights
// away.

const (
	// DefaultUsersPerPage and MaxUsersPerPage match the paginated collections
	// in docs/api.md.
	DefaultUsersPerPage = 10
	MaxUsersPerPage     = 100
)

// ListUsersParams is the query of GET /api/v1/users.
type ListUsersParams struct {
	Q       string
	Sort    string
	Dir     string
	Page    int
	PerPage int
}

// UserPage is one page of accounts and the metadata the API returns beside it.
type UserPage struct {
	Users   []User
	Page    int
	PerPage int
	Total   int
	Pages   int
}

// AdminUserPatch is the payload of PATCH /api/v1/users/{id}. A nil field is
// left alone.
type AdminUserPatch struct {
	Name     *string
	Email    *string
	IsAdmin  *bool
	Password *string
}

// ListUsers returns one page of accounts, optionally filtered by a name or
// email fragment.
func (s *Service) ListUsers(ctx context.Context, params ListUsersParams) (UserPage, error) {
	params = params.normalized()
	where, filterArgs := userFilter(params.Q)

	var total int
	if err := s.db.Reader().QueryRowContext(ctx,
		"SELECT COUNT(*) FROM users "+where, filterArgs...).Scan(&total); err != nil {
		return UserPage{}, fmt.Errorf("count users: %w", err)
	}

	page := UserPage{
		Users:   []User{},
		Page:    params.Page,
		PerPage: params.PerPage,
		Total:   total,
	}
	if total > 0 {
		page.Pages = (total + params.PerPage - 1) / params.PerPage
	}

	offset := (params.Page - 1) * params.PerPage
	query := selectUsers + " FROM users " + where +
		" ORDER BY " + userOrder(params.Sort, params.Dir) + " LIMIT ? OFFSET ?"

	args := append(append([]any{}, filterArgs...), params.PerPage, offset)
	rows, err := s.db.Reader().QueryContext(ctx, query, args...)
	if err != nil {
		return UserPage{}, fmt.Errorf("list users: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		user, err := scanUser(rows)
		if err != nil {
			return UserPage{}, fmt.Errorf("scan user: %w", err)
		}
		page.Users = append(page.Users, user)
	}
	if err := rows.Err(); err != nil {
		return UserPage{}, fmt.Errorf("read users: %w", err)
	}

	return page, nil
}

func (p ListUsersParams) normalized() ListUsersParams {
	if p.Page < 1 {
		p.Page = 1
	}
	if p.PerPage <= 0 {
		p.PerPage = DefaultUsersPerPage
	}
	if p.PerPage > MaxUsersPerPage {
		p.PerPage = MaxUsersPerPage
	}

	switch strings.TrimSpace(p.Sort) {
	case "email", "is_admin", "created_at":
		p.Sort = strings.TrimSpace(p.Sort)
	default:
		p.Sort = "name"
	}

	if strings.EqualFold(strings.TrimSpace(p.Dir), "desc") {
		p.Dir = "desc"
	} else {
		p.Dir = "asc"
	}

	return p
}

func userFilter(term string) (string, []any) {
	term = strings.TrimSpace(term)
	if term == "" {
		return "", nil
	}

	pattern := "%" + escapeLike(strings.ToLower(term)) + "%"
	where := `WHERE (
		LOWER(name) LIKE ? ESCAPE '\'
		OR LOWER(email) LIKE ? ESCAPE '\'
	)`

	return where, []any{pattern, pattern}
}

func userOrder(sort, dir string) string {
	const tieBreak = ", name ASC, id ASC"

	switch sort {
	case "email":
		return "email " + dir + tieBreak
	case "is_admin":
		return "is_admin " + dir + tieBreak
	case "created_at":
		return "created_at " + dir + tieBreak
	default:
		return "name " + dir + ", id " + dir
	}
}

// escapeLike makes %, _ and \ literal in a LIKE pattern.
func escapeLike(value string) string {
	replacer := strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`)
	return replacer.Replace(value)
}

// AdminUpdateUser applies an administrator's patch to one account. Setting a
// password on somebody else is a temporary password: it signs them out and
// forces a change on the next sign-in. Setting your own password is an ordinary
// change and keeps you signed in.
func (s *Service) AdminUpdateUser(ctx context.Context, actorID, targetID string, patch AdminUserPatch) (User, error) {
	newHash := ""
	if patch.Password != nil {
		if err := ValidatePassword(*patch.Password); err != nil {
			return User{}, err
		}
		var err error
		if newHash, err = HashPassword(*patch.Password); err != nil {
			return User{}, err
		}
	}

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return User{}, fmt.Errorf("begin admin update: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	if err := adminActor(ctx, tx, actorID); err != nil {
		return User{}, err
	}

	user, hash, err := adminTarget(ctx, tx, targetID)
	if err != nil {
		return User{}, err
	}
	if err := applyAdminUpdate(ctx, tx, &user, hash, newHash, s.now().UTC(), actorID, targetID, patch); err != nil {
		return User{}, err
	}

	if err := tx.Commit(); err != nil {
		return User{}, fmt.Errorf("commit admin update: %w", err)
	}

	return s.userByID(ctx, targetID)
}

func adminTarget(ctx context.Context, tx *sql.Tx, targetID string) (User, string, error) {
	user, hash, err := userByIDWithHash(ctx, tx, targetID)
	if errors.Is(err, sql.ErrNoRows) {
		return User{}, "", ErrUserNotFound
	}
	if err != nil {
		return User{}, "", fmt.Errorf("read user %s: %w", targetID, err)
	}
	return user, hash, nil
}

// applyAdminUpdate writes the validated patch. The caller hashed a new password
// before opening the transaction, so argon2 work never holds the writer.
func applyAdminUpdate(
	ctx context.Context,
	tx *sql.Tx,
	user *User,
	hash, newHash string,
	now time.Time,
	actorID, targetID string,
	patch AdminUserPatch,
) error {
	if err := applyAdminFields(user, targetID, actorID, patch); err != nil {
		return err
	}
	if patch.Password != nil {
		hash = newHash
		// A password chosen by somebody else is temporary; your own is not.
		user.MustReset = targetID != actorID
	}

	user.UpdatedAt = now
	if err := updateAdminUser(ctx, tx, *user, hash); err != nil {
		return err
	}

	if patch.Password == nil || targetID == actorID {
		return nil
	}
	return revokeUserSessions(ctx, tx, targetID)
}

// adminActor reads the account performing an admin operation and refuses a
// caller without the administrator flag.
func adminActor(ctx context.Context, tx *sql.Tx, actorID string) error {
	actor, _, err := userByIDWithHash(ctx, tx, actorID)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrForbidden
	}
	if err != nil {
		return fmt.Errorf("read acting user %s: %w", actorID, err)
	}
	if !actor.IsAdmin {
		return ErrForbidden
	}
	return nil
}

// applyAdminFields validates and writes the fields of an admin patch.
func applyAdminFields(user *User, targetID, actorID string, patch AdminUserPatch) error {
	if patch.Name != nil {
		name := strings.TrimSpace(*patch.Name)
		if err := ValidateName(name); err != nil {
			return err
		}
		user.Name = name
	}
	if patch.Email != nil {
		email := strings.TrimSpace(*patch.Email)
		if err := ValidateEmail(email); err != nil {
			return err
		}
		user.Email = email
	}
	if patch.IsAdmin == nil {
		return nil
	}
	if targetID == actorID && !*patch.IsAdmin {
		return ErrCannotDemoteSelf
	}
	user.IsAdmin = *patch.IsAdmin
	return nil
}

func updateAdminUser(ctx context.Context, tx *sql.Tx, user User, hash string) error {
	if _, err := tx.ExecContext(ctx, `UPDATE users
		SET name = ?, email = ?, password_hash = ?, is_admin = ?, must_reset = ?, updated_at = ?
		WHERE id = ?`,
		user.Name, user.Email, hash, user.IsAdmin, user.MustReset,
		formatTime(user.UpdatedAt), user.ID); err != nil {
		if store.IsUniqueViolation(err) {
			return fieldError("email", "unique")
		}
		return fmt.Errorf("update user %s: %w", user.ID, err)
	}
	return nil
}

func revokeUserSessions(ctx context.Context, tx *sql.Tx, userID string) error {
	if _, err := tx.ExecContext(ctx, "DELETE FROM sessions WHERE user_id = ?", userID); err != nil {
		return fmt.Errorf("revoke sessions for %s: %w", userID, err)
	}
	return nil
}

// DeleteUser removes an account. It refuses to leave the household without an
// administrator and refuses a self-delete; because the actor is an
// administrator, those two rules cover every row that could strand the
// household.
func (s *Service) DeleteUser(ctx context.Context, actorID, targetID string) error {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin delete user: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	if err := adminActor(ctx, tx, actorID); err != nil {
		return err
	}

	target, _, err := userByIDWithHash(ctx, tx, targetID)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrUserNotFound
	}
	if err != nil {
		return fmt.Errorf("read user %s: %w", targetID, err)
	}

	if target.IsAdmin {
		var admins int
		if err := tx.QueryRowContext(ctx,
			"SELECT COUNT(*) FROM users WHERE is_admin = 1").Scan(&admins); err != nil {
			return fmt.Errorf("count admins: %w", err)
		}
		if admins <= 1 {
			return ErrLastAdmin
		}
	}
	if targetID == actorID {
		return ErrCannotDeleteSelf
	}

	if _, err := tx.ExecContext(ctx, "DELETE FROM users WHERE id = ?", targetID); err != nil {
		return fmt.Errorf("delete user %s: %w", targetID, err)
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit delete user: %w", err)
	}
	return nil
}

// queryRower is the part of *sql.DB and *sql.Tx the admin rules read through.
type queryRower interface {
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
}

// userByIDWithHash reads an account and its password hash. The admin rules need
// the flag and the update needs the hash, so they share one read.
func userByIDWithHash(ctx context.Context, q queryRower, userID string) (User, string, error) {
	var hash string
	user, err := scanUser(q.QueryRowContext(ctx,
		selectUsers+", password_hash FROM users WHERE id = ?", userID), &hash)
	if err != nil {
		return User{}, "", err
	}
	return user, hash, nil
}
