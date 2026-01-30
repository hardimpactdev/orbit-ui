<?php

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteProjectRequest;
use HardImpact\Orbit\Core\Jobs\CreateProjectJob;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Models\TemplateFavorite;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(
        protected ConnectorService $connector,
        protected EnvironmentManager $environments,
        protected ConfigurationService $config,
    ) {}

    /**
     * Create a new project in the active environment.
     * For local environments, creates project directly and dispatches job.
     * For remote environments, uses Saloon API connector.
     */
    public function store(Request $request)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active environment',
                ], 400);
            }

            return redirect()->back()->withErrors(['error' => 'No active environment']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'org' => 'nullable|string|max:255',
            'template' => 'nullable|string|max:500',
            'is_template' => 'boolean',
            'fork' => 'boolean',
            'visibility' => 'nullable|string|in:private,public',
            'php_version' => 'nullable|string|in:8.3,8.4,8.5',
            'db_driver' => 'nullable|string|in:sqlite,pgsql',
            'session_driver' => 'nullable|string|in:file,database,redis',
            'cache_driver' => 'nullable|string|in:file,database,redis',
            'queue_driver' => 'nullable|string|in:sync,database,redis',
        ]);

        // For local environments, handle directly without HTTP hop
        if ($environment->is_local) {
            return $this->storeLocal($request, $environment, $validated);
        }

        // For remote environments, use the API connector
        return $this->storeRemote($request, $environment, $validated);
    }

    /**
     * Create project directly for local environment (no HTTP hop).
     */
    protected function storeLocal(Request $request, $environment, array $validated)
    {
        $isTemplate = ! empty($validated['is_template']);
        $providedRepo = $validated['template'] ?? null;
        $isImportScenario = ! empty($providedRepo) && ! $isTemplate;

        // Save template as favorite if provided
        if (! empty($validated['template'])) {
            $template = TemplateFavorite::firstOrCreate(
                ['repo_url' => $validated['template']],
                ['display_name' => $this->extractTemplateName($validated['template'])]
            );
            $template->recordUsage([
                'db_driver' => $validated['db_driver'] ?? null,
                'session_driver' => $validated['session_driver'] ?? null,
                'cache_driver' => $validated['cache_driver'] ?? null,
                'queue_driver' => $validated['queue_driver'] ?? null,
            ]);
        }

        // Build options for the job
        $projectOptions = [
            'name' => $validated['name'],
            'org' => $validated['org'] ?? null,
            'template' => $validated['template'] ?? null,
            'is_template' => $validated['is_template'] ?? false,
            'fork' => $isImportScenario ? ($validated['fork'] ?? false) : false,
            'visibility' => $validated['visibility'] ?? 'private',
            'php_version' => $validated['php_version'] ?? null,
            'db_driver' => $validated['db_driver'] ?? null,
            'session_driver' => $validated['session_driver'] ?? null,
            'cache_driver' => $validated['cache_driver'] ?? null,
            'queue_driver' => $validated['queue_driver'] ?? null,
        ];

        $projectSlug = Str::slug($validated['name']);
        $config = $this->config->getConfig($environment);
        $paths = $config['success'] ? ($config['data']['paths'] ?? []) : [];
        $basePath = $paths[0] ?? '~/projects';
        $projectPath = rtrim((string) $basePath, '/').'/'.$projectSlug;

        // Create project in database
        $project = Project::create([
            'environment_id' => $environment->id,
            'name' => $validated['name'],
            'display_name' => $validated['name'],
            'slug' => $projectSlug,
            'path' => $projectPath,
            'php_version' => $validated['php_version'] ?? '8.4',
            'github_repo' => $validated['template'] ?? null,
            'has_public_folder' => false,
            'status' => Project::STATUS_QUEUED,
        ]);

        // Dispatch job (async)
        CreateProjectJob::dispatch($project->id, $projectOptions);

        // API requests get JSON response
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Project creation queued',
                'slug' => $projectSlug,
                'project' => $project,
            ]);
        }

        // Web requests get redirect with provisioning slug for WebSocket tracking
        // Use /projects for single-environment mode, /environments/{id}/projects for multi-environment
        $redirectUrl = config('orbit.multi_environment')
            ? route('environments.projects', ['environment' => $environment->id])
            : '/projects';

        return redirect($redirectUrl)
            ->with([
                'provisioning' => $projectSlug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Create project via API for remote environment.
     */
    protected function storeRemote(Request $request, $environment, array $validated)
    {
        $result = $this->connector->sendRequest(
            $environment,
            new CreateProjectRequest($validated)
        );

        if (! $result['success']) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to create project',
                ], 422);
            }

            return redirect()->back()->withErrors(['error' => $result['error'] ?? 'Failed to create project']);
        }

        // API requests get JSON response
        if ($request->wantsJson()) {
            return response()->json($result);
        }

        // Web requests get redirect with provisioning slug for WebSocket tracking
        $slug = $result['slug'] ?? $result['data']['slug'] ?? null;

        // Use /projects for single-environment mode, /environments/{id}/projects for multi-environment
        $redirectUrl = config('orbit.multi_environment')
            ? route('environments.projects', ['environment' => $environment->id])
            : '/projects';

        return redirect($redirectUrl)
            ->with([
                'provisioning' => $slug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Extract template name from repo URL.
     */
    protected function extractTemplateName(string $template): string
    {
        // Handle owner/repo format
        if (preg_match('/([^\/]+)$/', $template, $matches)) {
            return str_replace('.git', '', $matches[1]);
        }

        return $template;
    }

    /**
     * Delete a project from the active environment.
     * Always uses the Saloon API connector for consistency.
     */
    public function destroy(Request $request, string $slug)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $keepDb = $request->boolean('keep_db', false);

        $result = $this->connector->sendRequest(
            $environment,
            new DeleteProjectRequest($slug, $keepDb)
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete project',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Set the PHP version for a project in the active environment.
     */
    public function setPhpVersion(Request $request, string $project)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $validated = $request->validate([
            'version' => 'required|string',
        ]);

        $result = $this->config->php($environment, $project, $validated['version']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to update PHP version',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Reset the PHP version for a project to the environment default.
     */
    public function resetPhpVersion(string $project)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $result = $this->config->phpReset($environment, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to reset PHP version',
            ], 422);
        }

        return response()->json($result);
    }
}
