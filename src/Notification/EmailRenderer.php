<?php

declare(strict_types=1);

namespace App\Notification;

use App\Config\Version;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Rendu des e-mails de notification via Twig.
 *
 * Produit un corps HTML (template `emails/alert.html.twig`) et un corps texte brut
 * (repli pour les clients sans HTML), en remplacement de la concaténation de chaînes.
 * Dispose de son propre environnement Twig (sans le contexte web : CSRF, auth…),
 * car les e-mails n'ont pas besoin des extensions du rendu de pages.
 */
final class EmailRenderer
{
    private Environment $twig;

    /**
     * @param string|null $templatesPath Racine des templates (défaut : <projet>/templates)
     */
    public function __construct(?string $templatesPath = null)
    {
        $path = $templatesPath ?? dirname(__DIR__, 2) . '/templates';
        $loader = new FilesystemLoader($path);
        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    /**
     * Rend le corps HTML d'une alerte.
     */
    public function renderAlertHtml(
        Severity $severity,
        ?NotificationCategory $category,
        ?string $family,
        string $title,
        string $message
    ): string {
        return $this->twig->render('emails/alert.html.twig', [
            'severity_code' => $severity->code(),
            'severity_label' => $severity->label(),
            'severity_emoji' => $severity->emoji(),
            'category_label' => $category?->label(),
            'category_emoji' => $category?->emoji(),
            'family' => $family !== null && $family !== '' ? strtoupper($family) : null,
            'title' => $title,
            'message' => $message,
            'version' => Version::getWithPrefix(),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Rend le corps texte brut d'une alerte (repli sans HTML).
     */
    public function renderAlertText(
        Severity $severity,
        ?NotificationCategory $category,
        ?string $family,
        string $title,
        string $message
    ): string {
        $lines = [];
        $header = '[' . $severity->code() . '] ' . $title;
        $lines[] = $header;
        $lines[] = str_repeat('=', max(8, mb_strlen($header)));
        $lines[] = '';
        if ($family !== null && $family !== '') {
            $lines[] = 'Appareil : ' . strtoupper($family);
        }
        if ($category !== null) {
            $lines[] = 'Catégorie : ' . $category->label();
        }
        $lines[] = 'Sévérité : ' . $severity->label() . ' (' . $severity->code() . ')';
        $lines[] = '';
        $lines[] = $message;
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = 'Supervision n³ ' . Version::getWithPrefix() . ' — ' . date('Y-m-d H:i:s');

        return implode("\n", $lines);
    }

    /**
     * Rend le corps HTML d'un e-mail de synthèse (digest) regroupant plusieurs alertes.
     *
     * @param list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}> $items
     */
    public function renderDigestHtml(array $items): string
    {
        return $this->twig->render('emails/digest.html.twig', [
            'items' => $items,
            'total' => count($items),
            'version' => Version::getWithPrefix(),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Rend le corps texte brut d'un e-mail de synthèse (digest).
     *
     * @param list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}> $items
     */
    public function renderDigestText(array $items): string
    {
        $lines = [];
        $lines[] = 'Synthèse des notifications de faible sévérité';
        $lines[] = str_repeat('=', 44);
        $lines[] = '';
        foreach ($items as $item) {
            $prefix = '[' . $item['severity_code'] . ']';
            if ($item['family'] !== null && $item['family'] !== '') {
                $prefix .= '[' . strtoupper($item['family']) . ']';
            }
            $suffix = $item['count'] > 1 ? ' (x' . $item['count'] . ')' : '';
            $lines[] = $prefix . ' ' . $item['subject'] . $suffix;
            if (trim($item['message']) !== '') {
                $lines[] = '    ' . str_replace("\n", "\n    ", trim($item['message']));
            }
            $lines[] = '    Dernière occurrence : ' . $item['last_seen'];
            $lines[] = '';
        }
        $lines[] = '-- ';
        $lines[] = 'Supervision n³ ' . Version::getWithPrefix() . ' — ' . date('Y-m-d H:i:s');

        return implode("\n", $lines);
    }
}
