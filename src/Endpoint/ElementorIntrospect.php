<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Elementor runtime introspection (v2.11).
 *
 * Two endpoints:
 *
 *   GET /wp-json/wplab/v1/elementor/widget-schema?widget=<type>
 *     Returns the controls registry for one widget type. Lets AI agents
 *     build _elementor_data JSON without reverse-engineering the shape.
 *     If `widget` is omitted, returns the list of registered widget types
 *     (names only).
 *
 *   GET /wp-json/wplab/v1/elementor/template-export?post_id=<id>
 *     Returns the parsed `_elementor_data` JSON of an existing page so AI
 *     agents can clone editor-built pages programmatically.
 *
 * Both return 503 ELEMENTOR_NOT_LOADED when Elementor is missing — they
 * are non-mutating, allowed on production.
 */
final class ElementorIntrospect
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/elementor/widget-schema',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handleWidgetSchema'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'widget' => ['required' => false, 'type' => 'string'],
                ],
            ]
        );
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/elementor/template-export',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handleTemplateExport'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'post_id' => ['required' => true, 'type' => 'integer'],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handleWidgetSchema(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        if (!self::elementorLoaded()) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'ELEMENTOR_NOT_LOADED',
                'error_message' => 'Elementor plugin is not active on this site.',
            ], 503);
        }

        $manager = \Elementor\Plugin::$instance->widgets_manager;
        $widgetType = trim((string) $req->get_param('widget'));

        if ($widgetType === '') {
            $types = array_keys($manager->get_widget_types());
            sort($types);
            return new WP_REST_Response([
                'ok' => true,
                'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
                'widget_count' => count($types),
                'widget_types' => $types,
            ], 200);
        }

        $widget = $manager->get_widget_types($widgetType);
        if (!$widget) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'WIDGET_NOT_FOUND',
                'error_message' => "Elementor has no registered widget of type '{$widgetType}'.",
            ], 404);
        }

        $controls = $widget->get_controls();
        $simplified = [];
        foreach ($controls as $name => $control) {
            $simplified[$name] = self::simplifyControl($name, $control);
        }

        return new WP_REST_Response([
            'ok' => true,
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
            'widget_type' => $widgetType,
            'widget_name' => method_exists($widget, 'get_name') ? $widget->get_name() : $widgetType,
            'widget_title' => method_exists($widget, 'get_title') ? $widget->get_title() : $widgetType,
            'control_count' => count($controls),
            'controls' => $simplified,
        ], 200);
    }

    public static function handleTemplateExport(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        if (!self::elementorLoaded()) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'ELEMENTOR_NOT_LOADED',
                'error_message' => 'Elementor plugin is not active on this site.',
            ], 503);
        }

        $postId = (int) $req->get_param('post_id');
        $post = get_post($postId);
        if (!$post) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'POST_NOT_FOUND',
                'error_message' => "no post with ID {$postId}",
            ], 404);
        }

        $editMode = (string) get_post_meta($postId, '_elementor_edit_mode', true);
        if ($editMode === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'NOT_AN_ELEMENTOR_PAGE',
                'error_message' => "post {$postId} was not built with Elementor (no _elementor_edit_mode meta)",
            ], 400);
        }

        $rawData = get_post_meta($postId, '_elementor_data', true);
        if (!is_string($rawData) || $rawData === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EMPTY_ELEMENTOR_DATA',
                'error_message' => "post {$postId} has no _elementor_data meta to export",
            ], 404);
        }
        $sections = json_decode($rawData, true);
        if (!is_array($sections)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_ELEMENTOR_DATA',
                'error_message' => 'stored _elementor_data is not valid JSON',
            ], 500);
        }

        $widgetTypes = self::collectWidgetTypes($sections);

        return new WP_REST_Response([
            'ok' => true,
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
            'post_id' => $postId,
            'post_title' => (string) $post->post_title,
            'post_slug' => (string) $post->post_name,
            'page_template' => (string) get_post_meta($postId, '_wp_page_template', true),
            'elementor_edit_mode' => $editMode,
            'elementor_template_type' => (string) get_post_meta($postId, '_elementor_template_type', true),
            'section_count' => count($sections),
            'widget_types_used' => $widgetTypes,
            'sections' => $sections,
        ], 200);
    }

    private static function elementorLoaded(): bool
    {
        return class_exists('\\Elementor\\Plugin')
            && isset(\Elementor\Plugin::$instance)
            && \Elementor\Plugin::$instance !== null
            && isset(\Elementor\Plugin::$instance->widgets_manager);
    }

    /**
     * Reduce Elementor's full control object to the fields a JSON-builder
     * needs. Strips render-time concerns (default values trees, dependency
     * conditions, responsive control mirror copies).
     *
     * @param array<string, mixed> $control
     * @return array<string, mixed>
     */
    private static function simplifyControl(string $name, array $control): array
    {
        $keep = [
            'type' => $control['type'] ?? null,
            'label' => $control['label'] ?? null,
            'section' => $control['section'] ?? null,
            'tab' => $control['tab'] ?? null,
            'default' => $control['default'] ?? null,
        ];
        if (isset($control['options']) && is_array($control['options'])) {
            $keep['options'] = $control['options'];
        }
        if (isset($control['fields']) && is_array($control['fields'])) {
            // Repeater inner-field shape — useful for items like accordion tabs.
            $keep['fields'] = array_keys($control['fields']);
        }
        if (isset($control['required']) && $control['required']) {
            $keep['required'] = true;
        }
        return array_filter($keep, static fn ($v) => $v !== null);
    }

    /** @param array<int, array<string, mixed>> $tree */
    private static function collectWidgetTypes(array $tree): array
    {
        $set = [];
        $walk = static function (array $node) use (&$walk, &$set): void {
            $type = isset($node['widgetType']) ? (string) $node['widgetType'] : '';
            if ($type !== '') {
                $set[$type] = true;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                foreach ($node['elements'] as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            }
        };
        foreach ($tree as $section) {
            if (is_array($section)) {
                $walk($section);
            }
        }
        $keys = array_keys($set);
        sort($keys);
        return $keys;
    }
}
