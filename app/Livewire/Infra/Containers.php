<?php

declare(strict_types=1);

namespace App\Livewire\Infra;

use App\Services\DockerService;
use App\Services\EditionService;
use Livewire\Component;

class Containers extends Component
{
    public string $initialSection = 'overview';

    // Data
    public array $containers  = [];
    public array $images      = [];
    public array $volumes     = [];
    public array $networks    = [];
    public array $swarmInfo   = ['active' => false];
    public array $swarmNodes  = [];
    public array $swarmServices = [];
    public array $containerStats = [];
    public bool  $dockerAvailable = false;

    // Flash
    public ?string $flashMessage = null;
    public string  $flashType    = 'success';

    // Inspect / Logs modal
    public bool  $showInspect  = false;
    public array $inspectData  = [];
    public bool  $showLogs     = false;
    public string $logsData    = '';
    public string $logsTarget  = '';

    // Create container modal
    public bool   $showCreate   = false;
    public bool   $cliPasteMode = false;
    public string $cliCommand   = '';
    public array  $createForm   = [
        'image'    => '',
        'name'     => '',
        'ports'    => [''],
        'env'      => [''],
        'volumes'  => [''],
        'network'  => '',
        'restart'  => 'unless-stopped',
        'memory'   => '',
        'cpus'     => '',
        'hostname' => '',
        'command'  => '',
    ];

    // Edit container modal
    public bool   $showEdit      = false;
    public string $editTarget    = '';
    public array  $editForm      = [
        'restart' => '',
        'memory'  => '',
        'cpus'    => '',
    ];

    // Pull image modal
    public bool   $showPull    = false;
    public string $pullInput   = '';
    public bool   $pulling     = false;
    public bool   $showRetagImage = false;
    public string $retagSourceImage = '';
    public string $retagTargetImage = '';

    // Create volume modal
    public bool   $showCreateVolume = false;
    public string $newVolumeName    = '';
    public string $newVolumeDriver  = 'local';

    // Create network modal
    public bool   $showCreateNetwork = false;
    public string $newNetworkName    = '';
    public string $newNetworkDriver  = 'bridge';
    public bool   $showCloneNetwork  = false;
    public string $cloneSourceNetwork = '';
    public string $cloneNetworkName   = '';
    public string $cloneNetworkDriver = 'bridge';

    // Swarm scale modal
    public bool   $showScale      = false;
    public string $scaleService   = '';
    public int    $scaleReplicas  = 1;

    // Swarm init modal
    public bool   $showSwarmInit   = false;
    public string $swarmAdvertise  = '';

    // Template deploy
    public string $deployingTemplate = '';

    public function mount(?string $section = null): void
    {
        if (is_string($section) && $section !== '') {
            $this->initialSection = $section;
        }
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function loadPageData(): void
    {
        $docker = app(DockerService::class);
        $this->dockerAvailable = $docker->isAvailable();

        if (! $this->dockerAvailable) {
            return;
        }

        match ($this->initialSection) {
            'overview'   => $this->loadOverview($docker),
            'containers' => $this->loadContainers($docker),
            'images'     => $this->loadImages($docker),
            'volumes'    => $this->loadVolumes($docker),
            'networks'   => $this->loadNetworks($docker),
            'swarm'      => $this->loadSwarm($docker),
            'databases'  => $this->loadDatabases($docker),
            default      => null,
        };
    }

    private function loadOverview(DockerService $docker): void
    {
        $this->containers     = $docker->listContainers(true);
        $this->images         = $docker->listImages();
        $this->volumes        = $docker->listVolumes();
        $this->containerStats = $docker->getAllContainerStats();
    }

    private function loadContainers(DockerService $docker): void
    {
        $this->containers = $docker->listContainers(true);
        $this->networks   = $docker->listNetworks();
        $this->volumes    = $docker->listVolumes();
    }

    private function loadImages(DockerService $docker): void
    {
        $this->images = $docker->listImages();
    }

    private function loadVolumes(DockerService $docker): void
    {
        $this->volumes = $docker->listVolumes();
    }

    private function loadNetworks(DockerService $docker): void
    {
        $this->networks = $docker->listNetworks();
    }

    private function loadSwarm(DockerService $docker): void
    {
        $this->swarmInfo     = $docker->getSwarmInfo();
        $this->swarmNodes    = $docker->listSwarmNodes();
        $this->swarmServices = $docker->listSwarmServices();
    }

    private function loadDatabases(DockerService $docker): void
    {
        $this->containers = array_filter(
            $docker->listContainers(true),
            fn ($c) => $this->isDatabaseContainer($c),
        );
        $this->containers = array_values($this->containers);
    }

    // ─── Container Actions ────────────────────────────────────────────────────

    public function startContainer(string $id): void
    {
        $result = app(DockerService::class)->startContainer($id);
        $this->flash($result['success'] ? 'Container started.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadContainers(app(DockerService::class));
    }

    public function stopContainer(string $id): void
    {
        $result = app(DockerService::class)->stopContainer($id);
        $this->flash($result['success'] ? 'Container stopped.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadContainers(app(DockerService::class));
    }

    public function restartContainer(string $id): void
    {
        $result = app(DockerService::class)->restartContainer($id);
        $this->flash($result['success'] ? 'Container restarted.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadContainers(app(DockerService::class));
    }

    public function removeContainer(string $id): void
    {
        $result = app(DockerService::class)->removeContainer($id, true);
        $this->flash($result['success'] ? 'Container removed.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadContainers(app(DockerService::class));
    }

    public function viewLogs(string $id): void
    {
        $this->logsTarget = $id;
        $this->logsData   = app(DockerService::class)->getContainerLogs($id);
        $this->showLogs   = true;
    }

    public function inspectContainer(string $id): void
    {
        $this->inspectData = app(DockerService::class)->inspectContainer($id);
        $this->showInspect = true;
    }

    public function openEditModal(string $id): void
    {
        $data = app(DockerService::class)->inspectContainer($id);
        $this->editTarget = $id;
        $this->editForm   = [
            'restart' => $data['HostConfig']['RestartPolicy']['Name'] ?? '',
            'memory'  => $data['HostConfig']['Memory'] > 0 ? (string) ($data['HostConfig']['Memory'] / 1024 / 1024) . 'm' : '',
            'cpus'    => (string) ($data['HostConfig']['NanoCpus'] / 1e9 ?: ''),
        ];
        $this->showEdit = true;
    }

    public function updateContainer(): void
    {
        $result = app(DockerService::class)->updateContainer($this->editTarget, $this->editForm);
        $this->flash($result['success'] ? 'Container updated.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->showEdit = false;
        $this->loadContainers(app(DockerService::class));
    }

    public function parseCli(): void
    {
        if (trim($this->cliCommand) === '') {
            return;
        }

        $parsed = app(DockerService::class)->parseRunCommand($this->cliCommand);

        $this->createForm = [
            'image'    => $parsed['image'],
            'name'     => $parsed['name'],
            'ports'    => array_values(array_filter($parsed['ports'])) ?: [''],
            'env'      => array_values(array_filter($parsed['env'])) ?: [''],
            'volumes'  => array_values(array_filter($parsed['volumes'])) ?: [''],
            'network'  => $parsed['network'],
            'restart'  => $parsed['restart'] ?: 'unless-stopped',
            'memory'   => $parsed['memory'],
            'cpus'     => $parsed['cpus'],
            'hostname' => $parsed['hostname'],
            'command'  => $parsed['command'],
        ];

        $this->cliPasteMode = false;
    }

    public function createContainer(): void
    {
        $this->validate([
            'createForm.image' => 'required|string|max:255',
        ]);

        $result = app(DockerService::class)->createContainer($this->createForm);

        if ($result['success']) {
            $this->flash('Container created successfully.');
            $this->showCreate = false;
            $this->resetCreateForm();
            $this->loadContainers(app(DockerService::class));
        } else {
            $this->flash($result['error'], 'error');
        }
    }

    public function addCreateField(string $field): void
    {
        $this->createForm[$field][] = '';
    }

    public function removeCreateField(string $field, int $index): void
    {
        array_splice($this->createForm[$field], $index, 1);
        if (empty($this->createForm[$field])) {
            $this->createForm[$field] = [''];
        }
    }

    private function resetCreateForm(): void
    {
        $this->createForm = [
            'image'    => '',
            'name'     => '',
            'ports'    => [''],
            'env'      => [''],
            'volumes'  => [''],
            'network'  => '',
            'restart'  => 'unless-stopped',
            'memory'   => '',
            'cpus'     => '',
            'hostname' => '',
            'command'  => '',
        ];
        $this->cliCommand   = '';
        $this->cliPasteMode = false;
    }

    // ─── Image Actions ────────────────────────────────────────────────────────

    public function pullImage(): void
    {
        if (trim($this->pullInput) === '') {
            return;
        }

        $this->pulling = true;
        $result = app(DockerService::class)->pullImage(trim($this->pullInput));
        $this->pulling  = false;
        $this->showPull = false;
        $this->pullInput = '';

        $this->flash($result['success'] ? 'Image pulled successfully.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadImages(app(DockerService::class));
    }

    public function removeImage(string $id): void
    {
        $result = app(DockerService::class)->removeImage($id, false);
        $this->flash($result['success'] ? 'Image removed.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadImages(app(DockerService::class));
    }

    public function inspectImage(string $id): void
    {
        $this->inspectData = app(DockerService::class)->inspectImage($id);
        $this->showInspect = true;
    }

    public function openRetagImageModal(string $source): void
    {
        $source = trim($source);
        if ($source === '') {
            return;
        }

        $this->retagSourceImage = $source;
        $this->retagTargetImage = str_contains($source, ':') && ! str_starts_with($source, 'sha256:')
            ? $source
            : '';
        $this->showRetagImage = true;
    }

    public function retagImage(): void
    {
        $this->validate([
            'retagSourceImage' => 'required|string|max:255',
            'retagTargetImage' => 'required|string|max:255',
        ]);

        $result = app(DockerService::class)->tagImage($this->retagSourceImage, $this->retagTargetImage);
        $this->flash($result['success'] ? 'Image retagged.' : $result['error'], $result['success'] ? 'success' : 'error');

        if ($result['success']) {
            $this->showRetagImage = false;
            $this->retagSourceImage = '';
            $this->retagTargetImage = '';
            $this->loadImages(app(DockerService::class));
        }
    }

    // ─── Volume Actions ───────────────────────────────────────────────────────

    public function createVolume(): void
    {
        $this->validate(['newVolumeName' => 'required|string|max:128|alpha_dash']);

        $result = app(DockerService::class)->createVolume($this->newVolumeName, $this->newVolumeDriver);
        $this->flash($result['success'] ? 'Volume created.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->showCreateVolume = false;
        $this->newVolumeName    = '';
        $this->loadVolumes(app(DockerService::class));
    }

    public function removeVolume(string $name): void
    {
        $result = app(DockerService::class)->removeVolume($name);
        $this->flash($result['success'] ? 'Volume removed.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadVolumes(app(DockerService::class));
    }

    public function inspectVolume(string $name): void
    {
        $this->inspectData = app(DockerService::class)->inspectVolume($name);
        $this->showInspect = true;
    }

    // ─── Network Actions ──────────────────────────────────────────────────────

    public function createNetwork(): void
    {
        $this->validate(['newNetworkName' => 'required|string|max:128|alpha_dash']);

        $result = app(DockerService::class)->createNetwork($this->newNetworkName, $this->newNetworkDriver);
        $this->flash($result['success'] ? 'Network created.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->showCreateNetwork = false;
        $this->newNetworkName    = '';
        $this->loadNetworks(app(DockerService::class));
    }

    public function removeNetwork(string $name): void
    {
        $result = app(DockerService::class)->removeNetwork($name);
        $this->flash($result['success'] ? 'Network removed.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->loadNetworks(app(DockerService::class));
    }

    public function inspectNetwork(string $name): void
    {
        $this->inspectData = app(DockerService::class)->inspectNetwork($name);
        $this->showInspect = true;
    }

    public function openCloneNetworkModal(string $sourceName, ?string $driver = null): void
    {
        $sourceName = trim($sourceName);
        if ($sourceName === '') {
            return;
        }

        $this->cloneSourceNetwork = $sourceName;
        $this->cloneNetworkDriver = trim((string) $driver) !== '' ? (string) $driver : 'bridge';
        $this->cloneNetworkName = $sourceName.'-copy';
        $this->showCloneNetwork = true;
    }

    public function cloneNetwork(): void
    {
        $this->validate([
            'cloneSourceNetwork' => 'required|string|max:128',
            'cloneNetworkName' => 'required|string|max:128|alpha_dash',
            'cloneNetworkDriver' => 'required|string|max:64',
        ]);

        $result = app(DockerService::class)->cloneNetwork(
            $this->cloneSourceNetwork,
            $this->cloneNetworkName,
            $this->cloneNetworkDriver,
        );

        $this->flash($result['success'] ? 'Network cloned.' : $result['error'], $result['success'] ? 'success' : 'error');

        if ($result['success']) {
            $this->showCloneNetwork = false;
            $this->cloneSourceNetwork = '';
            $this->cloneNetworkName = '';
            $this->cloneNetworkDriver = 'bridge';
            $this->loadNetworks(app(DockerService::class));
        }
    }

    // ─── Swarm Actions ────────────────────────────────────────────────────────

    public function initSwarm(): void
    {
        $result = app(DockerService::class)->initSwarm($this->swarmAdvertise);
        $this->flash($result['success'] ? 'Swarm initialised.' : $result['error'], $result['success'] ? 'success' : 'error');
        $this->showSwarmInit = false;
        $this->loadSwarm(app(DockerService::class));
    }

    public function openScaleModal(string $service, int $current): void
    {
        $this->scaleService  = $service;
        $this->scaleReplicas = $current;
        $this->showScale     = true;
    }

    public function scaleService(): void
    {
        $result = app(DockerService::class)->scaleService($this->scaleService, $this->scaleReplicas);
        $this->flash($result['success'] ? "Scaled {$this->scaleService} to {$this->scaleReplicas} replica(s)." : $result['error'], $result['success'] ? 'success' : 'error');
        $this->showScale = false;
        $this->loadSwarm(app(DockerService::class));
    }

    // ─── Templates ────────────────────────────────────────────────────────────

    public function deployTemplate(string $key): void
    {
        $template = $this->getTemplates()[$key] ?? null;
        if (! $template) {
            return;
        }

        $this->deployingTemplate = $key;
        $result = app(DockerService::class)->createContainer($template);
        $this->deployingTemplate = '';

        $this->flash(
            $result['success'] ? "Deployed {$template['name']} successfully." : $result['error'],
            $result['success'] ? 'success' : 'error',
        );
    }

    public function loadTemplateIntoForm(string $key): void
    {
        $template = $this->getTemplates()[$key] ?? null;
        if (! $template) {
            return;
        }

        $this->createForm = [
            'image'    => $template['image'],
            'name'     => $template['name_default'] ?? '',
            'ports'    => $template['ports'] ?? [''],
            'env'      => $template['env'] ?? [''],
            'volumes'  => $template['volumes'] ?? [''],
            'network'  => $template['network'] ?? '',
            'restart'  => $template['restart'] ?? 'unless-stopped',
            'memory'   => $template['memory'] ?? '',
            'cpus'     => $template['cpus'] ?? '',
            'hostname' => '',
            'command'  => $template['command'] ?? '',
        ];

        $this->showCreate = true;
    }

    // ─── Database Viewer ──────────────────────────────────────────────────────

    public function launchAdminer(string $dbContainerName, string $dbType): void
    {
        $adminerName = 'adminer-' . $dbContainerName;

        $result = app(DockerService::class)->createContainer([
            'image'   => 'adminer:latest',
            'name'    => $adminerName,
            'ports'   => ['8080:8080'],
            'restart' => 'unless-stopped',
            'env'     => ["ADMINER_DEFAULT_SERVER={$dbContainerName}"],
            'volumes' => [],
            'network' => 'bridge',
        ]);

        $this->flash(
            $result['success'] ? "Adminer launched at http://localhost:8080 — connect to {$dbContainerName}." : $result['error'],
            $result['success'] ? 'success' : 'error',
        );

        $this->loadDatabases(app(DockerService::class));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }

    private function isDatabaseContainer(array $container): bool
    {
        $image = strtolower($container['Image'] ?? '');
        foreach (['mysql', 'mariadb', 'postgres', 'mongo', 'redis', 'mssql', 'cassandra', 'couchdb', 'elasticsearch'] as $db) {
            if (str_contains($image, $db)) {
                return true;
            }
        }

        return false;
    }

    public function getTemplates(): array
    {
        return [
            'nginx' => [
                'name'         => 'Nginx',
                'name_default' => 'nginx',
                'description'  => 'High-performance web server & reverse proxy.',
                'image'        => 'nginx:alpine',
                'ports'        => ['80:80', '443:443'],
                'env'          => [''],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'category'     => 'Web',
                'color'        => 'emerald',
            ],
            'mysql' => [
                'name'         => 'MySQL 8',
                'name_default' => 'mysql',
                'description'  => 'Popular relational database server.',
                'image'        => 'mysql:8',
                'ports'        => ['3306:3306'],
                'env'          => ['MYSQL_ROOT_PASSWORD=', 'MYSQL_DATABASE=app'],
                'volumes'      => ['mysql_data:/var/lib/mysql'],
                'restart'      => 'unless-stopped',
                'category'     => 'Database',
                'color'        => 'blue',
            ],
            'mariadb' => [
                'name'         => 'MariaDB',
                'name_default' => 'mariadb',
                'description'  => 'Community-developed fork of MySQL.',
                'image'        => 'mariadb:11',
                'ports'        => ['3306:3306'],
                'env'          => ['MARIADB_ROOT_PASSWORD=', 'MARIADB_DATABASE=app'],
                'volumes'      => ['mariadb_data:/var/lib/mysql'],
                'restart'      => 'unless-stopped',
                'category'     => 'Database',
                'color'        => 'blue',
            ],
            'postgres' => [
                'name'         => 'PostgreSQL 16',
                'name_default' => 'postgres',
                'description'  => 'Advanced open-source relational database.',
                'image'        => 'postgres:16-alpine',
                'ports'        => ['5432:5432'],
                'env'          => ['POSTGRES_PASSWORD=', 'POSTGRES_DB=app'],
                'volumes'      => ['postgres_data:/var/lib/postgresql/data'],
                'restart'      => 'unless-stopped',
                'category'     => 'Database',
                'color'        => 'indigo',
            ],
            'redis' => [
                'name'         => 'Redis',
                'name_default' => 'redis',
                'description'  => 'In-memory data structure store & cache.',
                'image'        => 'redis:7-alpine',
                'ports'        => ['6379:6379'],
                'env'          => [''],
                'volumes'      => ['redis_data:/data'],
                'restart'      => 'unless-stopped',
                'category'     => 'Cache',
                'color'        => 'rose',
            ],
            'mongodb' => [
                'name'         => 'MongoDB',
                'name_default' => 'mongodb',
                'description'  => 'Document-oriented NoSQL database.',
                'image'        => 'mongo:7',
                'ports'        => ['27017:27017'],
                'env'          => ['MONGO_INITDB_ROOT_USERNAME=admin', 'MONGO_INITDB_ROOT_PASSWORD='],
                'volumes'      => ['mongo_data:/data/db'],
                'restart'      => 'unless-stopped',
                'category'     => 'Database',
                'color'        => 'green',
            ],
            'adminer' => [
                'name'         => 'Adminer',
                'name_default' => 'adminer',
                'description'  => 'Lightweight database management web UI.',
                'image'        => 'adminer:latest',
                'ports'        => ['8080:8080'],
                'env'          => [''],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'category'     => 'Tools',
                'color'        => 'amber',
            ],
            'phpmyadmin' => [
                'name'         => 'phpMyAdmin',
                'name_default' => 'phpmyadmin',
                'description'  => 'Web-based MySQL/MariaDB administration tool.',
                'image'        => 'phpmyadmin:latest',
                'ports'        => ['8081:80'],
                'env'          => ['PMA_ARBITRARY=1'],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'category'     => 'Tools',
                'color'        => 'amber',
            ],
            'wordpress' => [
                'name'         => 'WordPress',
                'name_default' => 'wordpress',
                'description'  => 'Popular CMS platform.',
                'image'        => 'wordpress:latest',
                'ports'        => ['8090:80'],
                'env'          => ['WORDPRESS_DB_HOST=mysql', 'WORDPRESS_DB_USER=root', 'WORDPRESS_DB_PASSWORD=', 'WORDPRESS_DB_NAME=wordpress'],
                'volumes'      => ['wordpress_data:/var/www/html'],
                'restart'      => 'unless-stopped',
                'category'     => 'CMS',
                'color'        => 'sky',
            ],
            'mailpit' => [
                'name'         => 'Mailpit',
                'name_default' => 'mailpit',
                'description'  => 'Email testing tool with web inbox (SMTP + UI).',
                'image'        => 'axllent/mailpit:latest',
                'ports'        => ['8025:8025', '1025:1025'],
                'env'          => [''],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'category'     => 'Tools',
                'color'        => 'violet',
            ],
            'minio' => [
                'name'         => 'MinIO',
                'name_default' => 'minio',
                'description'  => 'S3-compatible object storage server.',
                'image'        => 'minio/minio:latest',
                'ports'        => ['9000:9000', '9001:9001'],
                'env'          => ['MINIO_ROOT_USER=admin', 'MINIO_ROOT_PASSWORD='],
                'volumes'      => ['minio_data:/data'],
                'restart'      => 'unless-stopped',
                'command'      => 'server /data --console-address ":9001"',
                'category'     => 'Storage',
                'color'        => 'orange',
            ],
            'rabbitmq' => [
                'name'         => 'RabbitMQ',
                'name_default' => 'rabbitmq',
                'description'  => 'Open-source message broker.',
                'image'        => 'rabbitmq:3-management-alpine',
                'ports'        => ['5672:5672', '15672:15672'],
                'env'          => ['RABBITMQ_DEFAULT_USER=admin', 'RABBITMQ_DEFAULT_PASS=secret'],
                'volumes'      => ['rabbitmq_data:/var/lib/rabbitmq'],
                'restart'      => 'unless-stopped',
                'category'     => 'Messaging',
                'color'        => 'orange',
            ],
            'grafana' => [
                'name'         => 'Grafana',
                'name_default' => 'grafana',
                'description'  => 'Analytics & monitoring dashboards.',
                'image'        => 'grafana/grafana:latest',
                'ports'        => ['3000:3000'],
                'env'          => ['GF_SECURITY_ADMIN_PASSWORD='],
                'volumes'      => ['grafana_data:/var/lib/grafana'],
                'restart'      => 'unless-stopped',
                'category'     => 'Monitoring',
                'color'        => 'orange',
            ],
            'prometheus' => [
                'name'         => 'Prometheus',
                'name_default' => 'prometheus',
                'description'  => 'Metrics collection and alerting toolkit.',
                'image'        => 'prom/prometheus:latest',
                'ports'        => ['9090:9090'],
                'env'          => [''],
                'volumes'      => ['prometheus_data:/prometheus'],
                'restart'      => 'unless-stopped',
                'category'     => 'Monitoring',
                'color'        => 'orange',
            ],
            'portainer' => [
                'name'         => 'Portainer CE',
                'name_default' => 'portainer',
                'description'  => 'Container management UI for Docker.',
                'image'        => 'portainer/portainer-ce:latest',
                'ports'        => ['9443:9443', '8000:8000'],
                'env'          => [''],
                'volumes'      => ['/var/run/docker.sock:/var/run/docker.sock', 'portainer_data:/data'],
                'restart'      => 'unless-stopped',
                'category'     => 'Tools',
                'color'        => 'teal',
            ],
            'node' => [
                'name'         => 'Node.js',
                'name_default' => 'nodejs-app',
                'description'  => 'Node.js runtime environment.',
                'image'        => 'node:20-alpine',
                'ports'        => ['3000:3000'],
                'env'          => ['NODE_ENV=production'],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'command'      => 'node index.js',
                'category'     => 'Runtime',
                'color'        => 'lime',
            ],
            'php' => [
                'name'         => 'PHP-FPM',
                'name_default' => 'php-fpm',
                'description'  => 'PHP FastCGI Process Manager.',
                'image'        => 'php:8.3-fpm-alpine',
                'ports'        => ['9000:9000'],
                'env'          => [''],
                'volumes'      => [''],
                'restart'      => 'unless-stopped',
                'category'     => 'Runtime',
                'color'        => 'indigo',
            ],
            'n8n' => [
                'name'         => 'n8n',
                'name_default' => 'n8n',
                'description'  => 'Workflow automation tool.',
                'image'        => 'n8nio/n8n:latest',
                'ports'        => ['5678:5678'],
                'env'          => [''],
                'volumes'      => ['n8n_data:/home/node/.n8n'],
                'restart'      => 'unless-stopped',
                'category'     => 'Automation',
                'color'        => 'pink',
            ],
            'netdata' => [
                'name'         => 'Netdata',
                'name_default' => 'netdata',
                'description'  => 'Real-time server and container monitoring.',
                'image'        => 'netdata/netdata:latest',
                'ports'        => ['19999:19999'],
                'env'          => [''],
                'volumes'      => ['/etc/passwd:/host/etc/passwd:ro', '/etc/group:/host/etc/group:ro', '/proc:/host/proc:ro', '/sys:/host/sys:ro', '/etc/os-release:/host/etc/os-release:ro'],
                'restart'      => 'unless-stopped',
                'category'     => 'Monitoring',
                'color'        => 'teal',
            ],
        ];
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(EditionService $edition): \Illuminate\View\View
    {
        $enterpriseInstalled = class_exists(\GitManagerEnterprise\Livewire\Infrastructure\Containers::class);

        return view('livewire.infra.containers', [
            'enterpriseInstalled' => $enterpriseInstalled,
            'isEnterprise'        => $enterpriseInstalled && $edition->current() === EditionService::ENTERPRISE,
            'templates'           => $this->getTemplates(),
        ])->layout('layouts.app', [
            'title'  => 'Infrastructure',
            'header' => view('livewire.infra.partials.header', [
                'title'    => 'Infrastructure',
                'subtitle' => 'Manage Docker containers, images, volumes, networks, and swarm services.',
            ]),
        ]);
    }
}
