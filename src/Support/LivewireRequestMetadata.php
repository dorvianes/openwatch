<?php

namespace Dorvianes\OpenWatch\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LivewireRequestMetadata
{
    private const MAX_COMPONENTS = 10;
    private const MAX_CALLS = 20;
    private const MAX_UPDATE_KEYS = 30;
    private const MAX_NAME_LENGTH = 120;

    /**
     * @return array<string, mixed>|null
     */
    public function parse(Request $request, SymfonyResponse $response): ?array
    {
        unset($response);

        $body = $this->jsonBody($request->getContent());

        if (! $this->isLivewireRequest($request, $body)) {
            return null;
        }

        $components = $this->components($body);

        return [
            'detected'        => true,
            'endpoint'        => $this->endpointSignal($request),
            'component_count' => count($components),
            'components'      => $components,
            'calls'           => $this->calls($body),
            'updates_count'   => $this->updatesCount($body),
            'update_keys'     => $this->updateKeys($body),
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function isLivewireRequest(Request $request, ?array $body): bool
    {
        if ($request->headers->has('X-Livewire')) {
            return true;
        }

        $path = '/' . ltrim($request->path(), '/');
        if (str_starts_with($path, '/livewire/')) {
            return true;
        }

        if ($body === null) {
            return false;
        }

        return isset($body['components'])
            || isset($body['fingerprint'])
            || isset($body['serverMemo'])
            || $this->hasLivewireUpdateShape($body);
    }

    private function endpointSignal(Request $request): string
    {
        return '/' . ltrim($request->path(), '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(string $content): ?array
    {
        if (trim($content) === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return list<array{name?: string, id?: string}>
     */
    private function components(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        $components = [];

        if (isset($body['components']) && is_array($body['components'])) {
            foreach ($body['components'] as $component) {
                if (! is_array($component)) {
                    continue;
                }

                $components[] = $this->componentFromLivewire3($component);
            }
        } elseif (isset($body['fingerprint']) && is_array($body['fingerprint'])) {
            $components[] = $this->componentFromFingerprint($body['fingerprint']);
        }

        return array_values(array_filter(
            array_slice($components, 0, self::MAX_COMPONENTS),
            static fn (array $component): bool => $component !== [],
        ));
    }

    /**
     * @param array<string, mixed> $component
     * @return array{name?: string, id?: string}
     */
    private function componentFromLivewire3(array $component): array
    {
        $memo = [];

        if (isset($component['snapshot']) && is_string($component['snapshot'])) {
            try {
                $snapshot = json_decode($component['snapshot'], true, flags: JSON_THROW_ON_ERROR);
                if (is_array($snapshot) && isset($snapshot['memo']) && is_array($snapshot['memo'])) {
                    $memo = $snapshot['memo'];
                }
            } catch (\JsonException) {
                $memo = [];
            }
        }

        return $this->componentFromFingerprint($memo);
    }

    /**
     * @param array<string, mixed> $fingerprint
     * @return array{name?: string, id?: string}
     */
    private function componentFromFingerprint(array $fingerprint): array
    {
        $component = [];

        if (isset($fingerprint['name']) && is_string($fingerprint['name'])) {
            $component['name'] = $this->safeName($fingerprint['name']);
        }

        if (isset($fingerprint['id']) && is_string($fingerprint['id'])) {
            $component['id'] = $this->safeName($fingerprint['id']);
        }

        return $component;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return list<string>
     */
    private function calls(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        $calls = [];

        foreach ($this->componentPayloads($body) as $payload) {
            if (isset($payload['calls']) && is_array($payload['calls'])) {
                foreach ($payload['calls'] as $call) {
                    if (is_array($call) && isset($call['method']) && is_string($call['method'])) {
                        $calls[] = $this->safeName($call['method']);
                    }
                }
            }

            if (isset($payload['updates']) && is_array($payload['updates'])) {
                foreach ($payload['updates'] as $update) {
                    if (
                        is_array($update)
                        && ($update['type'] ?? null) === 'callMethod'
                        && isset($update['payload'])
                        && is_array($update['payload'])
                        && isset($update['payload']['method'])
                        && is_string($update['payload']['method'])
                    ) {
                        $calls[] = $this->safeName($update['payload']['method']);
                    }
                }
            }
        }

        return array_values(array_unique(array_slice($calls, 0, self::MAX_CALLS)));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function updatesCount(?array $body): int
    {
        return count($this->updateKeys($body));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return list<string>
     */
    private function updateKeys(?array $body): array
    {
        if ($body === null) {
            return [];
        }

        $keys = [];

        foreach ($this->componentPayloads($body) as $payload) {
            if (! isset($payload['updates']) || ! is_array($payload['updates'])) {
                continue;
            }

            if (array_is_list($payload['updates'])) {
                foreach ($payload['updates'] as $update) {
                    if (
                        is_array($update)
                        && ($update['type'] ?? null) === 'syncInput'
                        && isset($update['payload'])
                        && is_array($update['payload'])
                        && isset($update['payload']['name'])
                        && is_string($update['payload']['name'])
                    ) {
                        $keys[] = $this->safeName($update['payload']['name']);
                    }
                }
            } else {
                foreach (array_keys($payload['updates']) as $key) {
                    if (is_string($key)) {
                        $keys[] = $this->safeName($key);
                    }
                }
            }
        }

        return array_values(array_unique(array_slice($keys, 0, self::MAX_UPDATE_KEYS)));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function componentPayloads(array $body): array
    {
        if (isset($body['components']) && is_array($body['components'])) {
            return array_values(array_filter($body['components'], 'is_array'));
        }

        return [$body];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function hasLivewireUpdateShape(array $body): bool
    {
        if (! isset($body['updates']) || ! is_array($body['updates'])) {
            return false;
        }

        foreach ($body['updates'] as $update) {
            if (is_array($update) && isset($update['type'], $update['payload'])) {
                return true;
            }
        }

        return false;
    }

    private function safeName(string $value): string
    {
        return substr($value, 0, self::MAX_NAME_LENGTH);
    }
}
