<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard's "This week" row, as SummariseWeeklyInsights works it out.
 *
 * @property array{from: string, to: string, streak: array{current_days: int, best_days: int}, recall_rate: array{percentage: ?int, previous_percentage: ?int, change_points: ?int}, study_time: array{minutes: int, topics: list<array{id: int, title: string, minutes: int}>}} $resource
 */
class WeeklyInsightsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'streak' => $this->resource['streak'],
            'recall_rate' => $this->resource['recall_rate'],
            'study_time' => $this->resource['study_time'],
        ];
    }
}
