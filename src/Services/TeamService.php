<?php

declare(strict_types=1);

namespace App\Services;

final class TeamService
{
    private array $teams;

    public function __construct()
    {
        $this->fetchTeams();
    }

    public function getTeams(): array
    {
        return $this->teams;
    }

    public function findRandom(): array
    {
        $randomIndex = array_rand($this->teams);
        return $this->teams[$randomIndex];
    }

    private function fetchTeams(): void
    {
        debugLog('[TeamService] Fetching teams');
        
        $url = 'https://www.sofascore.com/api/v1/unique-tournament/325/season/72034/standings/total';

        // Inicia o cURL
        $ch = curl_init($url);

        // Configurações do cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-type: application/json',
            'Accept: application/json'
        ]);

        // Executa a requisição
        $response = curl_exec($ch);

        // Fecha a conexão
        curl_close($ch);

        if (! $response) {
            echo 'Failed to fetch teams - CURL error' . PHP_EOL;
            $this->teams = $this->getFallbackTeams();
            return;
        }

        $data = json_decode($response, true);

        if (! $data) {
            debugLog('[TeamService] Failed to fetch teams - JSON error');
            $this->teams = $this->getFallbackTeams();
            return;
        }

        if (! isset($data['standings']['0']['rows'])) {
            debugLog('[TeamService] Failed to fetch teams - Invalid data');
            $this->teams = $this->getFallbackTeams();
            return;
        }

        $result = [];

        foreach ($data['standings'][0]['rows'] as $team) {
            $result[] = [
                'id' => $team['team']['id'],
                'name' => $team['team']['name'],
                'flag' => "https://img.sofascore.com/api/v1/team/{$team['team']['id']}/image",
            ];
        }

        debugLog('[TeamService] Teams fetched successfully. Total of ' . count($result) . ' teams');

        $this->teams = $result;
    }

    private function getFallbackTeams(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Real Madrid',
                'flag' => 'https://img.sofascore.com/api/v1/team/2829/image',
            ],
            [
                'id' => 2,
                'name' => 'Barcelona',
                'flag' => 'https://img.sofascore.com/api/v1/team/2817/image',
            ],
            [
                'id' => 3,
                'name' => 'Liverpool',
                'flag' => 'https://img.sofascore.com/api/v1/team/44/image',
            ],
            [
                'id' => 4,
                'name' => 'Manchester City',
                'flag' => 'https://img.sofascore.com/api/v1/team/17/image',
            ],
            [
                'id' => 5,
                'name' => 'Bayern Munich',
                'flag' => 'https://img.sofascore.com/api/v1/team/2672/image',
            ],
            [
                'id' => 6,
                'name' => 'Paris Saint-Germain',
                'flag' => 'https://img.sofascore.com/api/v1/team/1644/image',
            ],
            [
                'id' => 7,
                'name' => 'Chelsea',
                'flag' => 'https://img.sofascore.com/api/v1/team/38/image',
            ],
            [
                'id' => 8,
                'name' => 'Borussia Dortmund',
                'flag' => 'https://img.sofascore.com/api/v1/team/2673/image',
            ],
            [
                'id' => 9,
                'name' => 'Atletico Madrid',
                'flag' => 'https://img.sofascore.com/api/v1/team/2836/image',
            ],
            [
                'id' => 10,
                'name' => 'Inter Milan',
                'flag' => 'https://img.sofascore.com/api/v1/team/2697/image',
            ]
        ];
    }
}
