<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Service de protection CSRF simple basé sur les sessions.
 * 
 * Génère et valide des tokens CSRF pour protéger les formulaires POST
 * contre les attaques Cross-Site Request Forgery.
 */
class CsrfService
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;

    public function __construct()
    {
        // S'assurer que la session est démarrée
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Génère un nouveau token CSRF et le stocke en session.
     * 
     * @return string Le token CSRF généré
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;
        return $token;
    }

    /**
     * Récupère le token CSRF actuel ou en génère un nouveau.
     * 
     * @return string Le token CSRF
     */
    public function getToken(): string
    {
        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return $this->generateToken();
        }
        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Valide un token CSRF soumis.
     * 
     * @param string|null $submittedToken Le token soumis par le formulaire
     * @return bool True si le token est valide
     */
    public function validateToken(?string $submittedToken): bool
    {
        if ($submittedToken === null || !isset($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        // Comparaison timing-safe
        return hash_equals($_SESSION[self::TOKEN_NAME], $submittedToken);
    }

    /**
     * Valide et régénère le token (à utiliser après validation réussie).
     * 
     * @param string|null $submittedToken Le token soumis
     * @return bool True si le token était valide
     */
    public function validateAndRegenerate(?string $submittedToken): bool
    {
        $isValid = $this->validateToken($submittedToken);
        
        // Régénérer le token après validation pour éviter la réutilisation
        $this->generateToken();
        
        return $isValid;
    }

    /**
     * Génère le champ HTML caché pour le formulaire.
     * 
     * @return string Le HTML du champ input hidden
     */
    public function getHiddenField(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');
        return sprintf('<input type="hidden" name="_csrf_token" value="%s">', $token);
    }

    /**
     * Nom du champ de formulaire pour le token CSRF.
     */
    public static function getFieldName(): string
    {
        return '_csrf_token';
    }
}
