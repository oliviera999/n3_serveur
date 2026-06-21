<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\User;
use App\Repository\UserRepository;
use App\Security\AuthService;
use RuntimeException;

/**
 * Règles métier pour la gestion des utilisateurs.
 */
class UserService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    public function listUsers(): array
    {
        return $this->userRepository->findAll();
    }

    public function getUser(int $id): ?User
    {
        return $this->userRepository->findById($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: User}|array{errors: list<string>}
     */
    public function createUser(array $data): array
    {
        $errors = $this->validateUserData($data, true);
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $username = trim((string) $data['username']);
        if ($this->userRepository->findByUsername($username) !== null) {
            return ['errors' => ['Ce nom d\'utilisateur est déjà utilisé.']];
        }

        $id = $this->userRepository->create(
            username: $username,
            passwordHash: AuthService::hashPassword((string) $data['password']),
            role: (string) $data['role'],
            email: $this->nullableString($data['email'] ?? null),
            displayName: $this->nullableString($data['display_name'] ?? null),
            isActive: $this->parseBool($data['is_active'] ?? true),
        );

        $user = $this->userRepository->findById($id);
        if ($user === null) {
            throw new RuntimeException('Utilisateur créé mais introuvable.');
        }

        return ['user' => $user];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: User}|array{errors: list<string>}
     */
    public function updateUser(int $id, array $data, ?int $currentUserId = null): array
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            return ['errors' => ['Utilisateur introuvable.']];
        }

        $errors = $this->validateUserData($data, false);
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $role = (string) $data['role'];
        $isActive = array_key_exists('is_active', $data)
            ? $this->parseBool($data['is_active'])
            : false;

        if (!$isActive || $role !== User::ROLE_ADMIN) {
            $guardError = $this->guardLastAdmin($existing, $role, $isActive, $currentUserId);
            if ($guardError !== null) {
                return ['errors' => [$guardError]];
            }
        }

        $password = trim((string) ($data['password'] ?? ''));
        $passwordHash = null;
        if ($password !== '') {
            if (strlen($password) < 8) {
                return ['errors' => ['Le mot de passe doit contenir au moins 8 caractères.']];
            }
            $confirm = (string) ($data['password_confirm'] ?? '');
            if ($password !== $confirm) {
                return ['errors' => ['Les mots de passe ne correspondent pas.']];
            }
            $passwordHash = AuthService::hashPassword($password);
        }

        $this->userRepository->update(
            id: $id,
            email: $this->nullableString($data['email'] ?? null),
            displayName: $this->nullableString($data['display_name'] ?? null),
            role: $role,
            isActive: $isActive,
        );

        if ($passwordHash !== null) {
            $this->userRepository->updatePassword($id, $passwordHash);
        }

        $user = $this->userRepository->findById($id);
        if ($user === null) {
            throw new RuntimeException('Utilisateur mis à jour mais introuvable.');
        }

        return ['user' => $user];
    }

    /**
     * @return array{success: true}|array{errors: list<string>}
     */
    public function deleteUser(int $id, ?int $currentUserId = null): array
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            return ['errors' => ['Utilisateur introuvable.']];
        }

        if ($currentUserId !== null && $currentUserId === $id) {
            return ['errors' => ['Vous ne pouvez pas supprimer votre propre compte.']];
        }

        $guardError = $this->guardLastAdmin($existing, User::ROLE_READER, false, $currentUserId);
        if ($guardError !== null) {
            return ['errors' => [$guardError]];
        }

        if (!$this->userRepository->delete($id)) {
            return ['errors' => ['Impossible de supprimer l\'utilisateur.']];
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validateUserData(array $data, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate) {
            $username = trim((string) ($data['username'] ?? ''));
            if ($username === '') {
                $errors[] = 'Le nom d\'utilisateur est obligatoire.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
                $errors[] = 'Le nom d\'utilisateur doit contenir 3 à 64 caractères (lettres, chiffres, _ . -).';
            }

            $password = (string) ($data['password'] ?? '');
            if ($password === '') {
                $errors[] = 'Le mot de passe est obligatoire.';
            } elseif (strlen($password) < 8) {
                $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
            } elseif ($password !== (string) ($data['password_confirm'] ?? '')) {
                $errors[] = 'Les mots de passe ne correspondent pas.';
            }
        }

        $role = (string) ($data['role'] ?? '');
        if (!in_array($role, User::ROLES, true)) {
            $errors[] = 'Rôle invalide.';
        }

        $email = $this->nullableString($data['email'] ?? null);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail invalide.';
        }

        return $errors;
    }

    private function guardLastAdmin(User $user, string $newRole, bool $newActive, ?int $currentUserId): ?string
    {
        if ($user->role !== User::ROLE_ADMIN || !$user->isActive) {
            return null;
        }

        $willRemainAdmin = ($newRole === User::ROLE_ADMIN && $newActive);
        if ($willRemainAdmin) {
            return null;
        }

        if ($this->userRepository->countActiveByRole(User::ROLE_ADMIN) <= 1) {
            if ($currentUserId !== null && $currentUserId === $user->id) {
                return 'Vous êtes le dernier administrateur actif : impossible de retirer ce rôle ou de vous désactiver.';
            }
            return 'Impossible de supprimer ou de rétrograder le dernier administrateur actif.';
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $value;
    }
}
