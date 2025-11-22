<?php

namespace OurEdu\RequestTracker\Services;

use ReflectionClass;
use ReflectionMethod;

class ModuleExtractor
{
    /**
     * Extract module, submodule, and action from request data
     * 
     * @param string $path
     * @param string|null $routeName
     * @param string|null $controllerAction
     * @param array $config
     * @return array ['module' => string, 'submodule' => string, 'action' => string]
     */
    public static function extract(string $path, ?string $routeName, ?string $controllerAction, array $config = []): array
    {
        $module = null;
        $submodule = null;
        $action = null;

        // 1. Try custom patterns from config
        if (!empty($config['module_mapping']['patterns'])) {
            foreach ($config['module_mapping']['patterns'] as $pattern => $mapping) {
                if (str_contains($path, $pattern)) {
                    return self::parseMapping($mapping);
                }
            }
        }

        // 2. Try to extract from route name (Laravel convention)
        if ($routeName) {
            $result = self::extractFromRouteName($routeName);
            if ($result['module']) {
                return $result;
            }
        }

        // 3. Try to extract from path segments
        $result = self::extractFromPath($path, $config);
        if ($result['module']) {
            return $result;
        }

        // 4. Try to extract from controller action
        if ($controllerAction) {
            $result = self::extractFromController($controllerAction);
            if ($result['module']) {
                return $result;
            }
        }

        return [
            'module' => 'unknown',
            'submodule' => null,
            'action' => self::humanize($path)
        ];
    }

    /**
     * Parse mapping string: "module" or "module.submodule" or "module.submodule|Action"
     */
    protected static function parseMapping(string $mapping): array
    {
        $action = null;
        
        // Check for action (text after |)
        if (str_contains($mapping, '|')) {
            [$mapping, $action] = explode('|', $mapping, 2);
        }

        // Check for submodule (text after .)
        if (str_contains($mapping, '.')) {
            [$module, $submodule] = explode('.', $mapping, 2);
        } else {
            $module = $mapping;
            $submodule = null;
        }

        return [
            'module' => $module,
            'submodule' => $submodule,
            'action' => $action
        ];
    }

    /**
     * Extract from Laravel route name: "users.profile.show" -> module: users, submodule: profile
     */
    protected static function extractFromRouteName(string $routeName): array
    {
        $parts = explode('.', $routeName);
        
        $module = $parts[0] ?? null;
        $submodule = $parts[1] ?? null;
        $actionPart = $parts[2] ?? null;

        // Build action from action part
        $action = $actionPart ? ucfirst($actionPart) . ' ' . ucfirst($submodule ?? $module) : null;

        return [
            'module' => $module,
            'submodule' => $submodule,
            'action' => $action
        ];
    }

    /**
     * Extract from path: "api/v1/users/123/profile" -> module: users, submodule: profile
     */
    protected static function extractFromPath(string $path, array $config): array
    {
        $segments = array_filter(explode('/', $path));
        $segments = array_values($segments); // Re-index
        
        // Get segment index from config
        $moduleIndex = $config['module_mapping']['auto_extract_segment'] ?? 2;
        
        $module = null;
        $submodule = null;
        
        // Extract module (skip 'api', 'v1', etc.)
        if (isset($segments[$moduleIndex])) {
            $module = $segments[$moduleIndex];
            
            // Check if it's numeric (ID) - skip to next
            if (is_numeric($module) && isset($segments[$moduleIndex + 1])) {
                $module = $segments[$moduleIndex + 1];
                $submodule = $segments[$moduleIndex + 2] ?? null;
            } else {
                // Check for submodule
                if (isset($segments[$moduleIndex + 1]) && !is_numeric($segments[$moduleIndex + 1])) {
                    $submodule = $segments[$moduleIndex + 1];
                } elseif (isset($segments[$moduleIndex + 2])) {
                    $submodule = $segments[$moduleIndex + 2];
                }
            }
        }

        // Clean up (remove numeric IDs from submodule)
        if ($submodule && is_numeric($submodule)) {
            $submodule = null;
        }

        return [
            'module' => $module,
            'submodule' => $submodule,
            'action' => $submodule ? ucfirst($submodule) . ' in ' . ucfirst($module) : ucfirst($module ?? 'Resource')
        ];
    }

    /**
     * Extract from controller: "App\Http\Controllers\UserController@show"
     */
    protected static function extractFromController(string $controllerAction): array
    {
        // Extract controller name
        preg_match('/(\w+)Controller@(\w+)/', $controllerAction, $matches);
        
        if (!empty($matches)) {
            $controller = $matches[1] ?? null;
            $method = $matches[2] ?? null;
            
            $module = $controller ? strtolower($controller) : null;
            $action = $method && $controller ? ucfirst($method) . ' ' . ucfirst($controller) : null;
            
            return [
                'module' => $module,
                'submodule' => null,
                'action' => $action
            ];
        }

        return ['module' => null, 'submodule' => null, 'action' => null];
    }

    /**
     * Convert path to human-readable action
     */
    protected static function humanize(string $path): string
    {
        $segments = array_filter(explode('/', $path));
        $segments = array_values($segments);
        
        // Remove common prefixes and numeric IDs
        $meaningful = array_filter($segments, function($segment) {
            return !in_array(strtolower($segment), ['api', 'v1', 'v2']) && !is_numeric($segment);
        });
        
        if (empty($meaningful)) {
            return 'Resource Access';
        }
        
        return ucwords(implode(' ', $meaningful));
    }
}
