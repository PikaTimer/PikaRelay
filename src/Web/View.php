<?php
/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


declare(strict_types=1);

namespace PikaRelay\Web;

use PikaRelay\Http\Request;
use PikaRelay\Http\Response;
use Throwable;

/**
 * Renders the PHP templates in src/views. Templates get these helpers:
 *   $e($value)        HTML-escape
 *   $url('/route')    link to a route in this install
 *   $asset('file')    link to a static file in this install
 *   $time($ms)        <time> element (converted to the viewer's local time by app.js)
 *   $ago($ms)         "12s ago"-style age relative to $now
 */
final class View
{
    /**
     * @param array<string, mixed> $vars   template variables
     * @param array<string, mixed> $layout layout variables: loggedIn (bool), csrf (string), flash (array)
     */
    public static function page(Request $request, string $template, string $title, array $vars = [], array $layout = [], int $status = 200): Response
    {
        $helpers = self::helpers($request, isset($vars['now']) ? (int) $vars['now'] : (int) floor(microtime(true) * 1000));
        $content = self::capture($template, $vars + $helpers);
        $html = self::capture('layout', [
            'title' => $title,
            'content' => $content,
            'loggedIn' => !empty($layout['loggedIn']),
            'csrf' => (string) ($layout['csrf'] ?? ''),
            'flash' => isset($layout['flash']) && is_array($layout['flash']) ? $layout['flash'] : [],
        ] + $helpers);
        return Response::html($html, $status);
    }

    public static function errorPage(Request $request, int $status, string $message): Response
    {
        try {
            return self::page($request, 'error', 'Error ' . $status, ['status' => $status, 'message' => $message], [], $status);
        } catch (Throwable $e) {
            return Response::html('<!doctype html><title>Error</title><p>' . self::e($message) . '</p>', $status);
        }
    }

    /**
     * @param mixed $value
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array<string, callable>
     */
    private static function helpers(Request $request, int $now): array
    {
        return [
            'e' => static function ($value): string {
                return View::e($value);
            },
            'url' => static function (string $route) use ($request): string {
                return View::e($request->url($route));
            },
            'asset' => static function (string $file) use ($request): string {
                return View::e($request->assetUrl($file));
            },
            'time' => static function ($ms): string {
                if ($ms === null) {
                    return '<span class="muted">never</span>';
                }
                $ms = (int) $ms;
                $iso = gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . 'Z';
                return '<time datetime="' . $iso . '" data-ms="' . $ms . '">' . gmdate('Y-m-d H:i:s', intdiv($ms, 1000)) . ' UTC</time>';
            },
            'ago' => static function ($ms) use ($now): string {
                if ($ms === null) {
                    return 'never';
                }
                $s = max(0, intdiv($now - (int) $ms, 1000));
                if ($s < 120) {
                    return $s . 's ago';
                }
                if ($s < 7200) {
                    return intdiv($s, 60) . 'm ago';
                }
                if ($s < 172800) {
                    return intdiv($s, 3600) . 'h ago';
                }
                return intdiv($s, 86400) . 'd ago';
            },
        ];
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function capture(string $template, array $vars): string
    {
        $file = PIKA_ROOT . '/src/views/' . $template . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
