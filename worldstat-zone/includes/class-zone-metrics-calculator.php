<?php
/**
 * Metrics Calculator for Zone Analysis
 *
 * Calculates composite scores for:
 * - Ergonomics (lighting, safety, comfort)
 * - Overall rating
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Metrics_Calculator {

    private $last_ergonomics_components = [];

    /**
     * Calculate ergonomics score (0-100) based on lighting, safety, comfort
     */
    public function calculate_ergonomics_score( array $metrics ): float {
        $weights = [
            'lighting' => 0.30,
            'safety'   => 0.35,
            'comfort'  => 0.35,
        ];

        $score = 0;
        $this->last_ergonomics_components = [];

        foreach ( $weights as $metric => $weight ) {
            $value = $metrics[ $metric ] ?? 50;
            $value = max( 0, min( 100, $value ) );
            
            $score += $value * $weight;
            $this->last_ergonomics_components[ $metric ] = [
                'value' => $value,
                'weight' => $weight,
                'contribution' => $value * $weight,
            ];
        }

        return round( $score, 1 );
    }

    /**
     * Get overall zone rating based on ergonomics
     */
    public function get_overall_rating( float $ergonomics ): array {
        $rating = [
            'score' => round( $ergonomics, 1 ),
            'level' => $this->get_rating_level( $ergonomics ),
            'color' => $this->get_rating_color( $ergonomics ),
        ];

        return $rating;
    }

    /**
     * Get rating level based on score
     */
    private function get_rating_level( float $score ): string {
        if ( $score >= 80 ) return 'Отлично';
        if ( $score >= 60 ) return 'Хорошо';
        if ( $score >= 40 ) return 'Средне';
        if ( $score >= 20 ) return 'Ниже среднего';
        return 'Плохо';
    }

    /**
     * Get color for rating
     */
    private function get_rating_color( float $score ): string {
        if ( $score >= 80 ) return '#10b981';
        if ( $score >= 60 ) return '#3b82f6';
        if ( $score >= 40 ) return '#f59e0b';
        if ( $score >= 20 ) return '#f97316';
        return '#ef4444';
    }

    /**
     * Get last ergonomics components for detailed breakdown
     */
    public function get_last_ergonomics_components(): array {
        return $this->last_ergonomics_components;
    }

    /**
     * Analyze zone strengths and weaknesses
     */
    public function analyze_zone( array $zone ): array {
        $strengths = [];
        $weaknesses = [];

        $threshold_high = 70;
        $threshold_low = 40;

        $metrics_to_check = [
            'lighting' => 'Освещение',
            'safety'   => 'Безопасность',
            'comfort'  => 'Комфорт',
        ];

        foreach ( $metrics_to_check as $key => $label ) {
            if ( isset( $zone[ $key ] ) ) {
                $value = (float) $zone[ $key ];
                if ( $value >= $threshold_high ) {
                    $strengths[] = $label;
                } elseif ( $value <= $threshold_low && $value > 0 ) {
                    $weaknesses[] = $label;
                }
            }
        }

        // Check ergonomics composite
        if ( $zone['ergonomics'] >= $threshold_high ) {
            $strengths[] = 'Высокая эргономика';
        } elseif ( $zone['ergonomics'] <= $threshold_low && $zone['ergonomics'] > 0 ) {
            $weaknesses[] = 'Низкая эргономика';
        }

        // Additional metrics
        if ( isset( $zone['green_index'] ) && $zone['green_index'] >= 70 ) {
            $strengths[] = 'Зеленые зоны';
        } elseif ( isset( $zone['green_index'] ) && $zone['green_index'] <= 40 ) {
            $weaknesses[] = 'Недостаток зелени';
        }

        if ( isset( $zone['noise_level'] ) && $zone['noise_level'] <= 30 ) {
            $strengths[] = 'Тихая зона';
        } elseif ( isset( $zone['noise_level'] ) && $zone['noise_level'] >= 70 ) {
            $weaknesses[] = 'Высокий уровень шума';
        }

        if ( isset( $zone['air_quality'] ) && $zone['air_quality'] >= 70 ) {
            $strengths[] = 'Хорошее качество воздуха';
        } elseif ( isset( $zone['air_quality'] ) && $zone['air_quality'] <= 40 ) {
            $weaknesses[] = 'Плохое качество воздуха';
        }

        return [
            'strengths' => array_slice( $strengths, 0, 5 ),
            'weaknesses' => array_slice( $weaknesses, 0, 5 ),
        ];
    }
}