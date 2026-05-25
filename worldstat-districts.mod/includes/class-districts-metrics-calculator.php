<?php
/**
 * Metrics Calculator for District Analysis
 *
 * Calculates composite scores for:
 * - Comfort (green spaces, ecology, air quality, noise, social)
 * - Safety (crime rate, infrastructure, social cohesion)
 * - Functionality (infrastructure, transport, economy, social)
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Metrics_Calculator {

    private $last_comfort_components = [];
    private $last_safety_components = [];
    private $last_functionality_components = [];

    /**
     * Calculate comfort score (0-100)
     * 
     * Components:
     * - Green Index (25%): parks, trees, green spaces
     * - Ecology Index (20%): environmental quality
     * - Air Quality (25%): clean air, low pollution
     * - Noise Level (15%): low noise, peacefulness
     * - Social Index (15%): community, amenities
     */
    public function calculate_comfort_score( array $metrics ): float {
        $weights = [
            'green_index' => 0.25,
            'ecology_index' => 0.20,
            'air_quality' => 0.25,
            'noise_level' => 0.15,  // inverted (higher noise = lower comfort)
            'social_index' => 0.15,
        ];

        $score = 0;
        $this->last_comfort_components = [];

        foreach ( $weights as $metric => $weight ) {
            $value = $metrics[ $metric ] ?? 50; // default to 50 if missing
            
            // Invert noise level (higher noise = lower score)
            if ( $metric === 'noise_level' ) {
                $value = 100 - $value;
            }
            
            // Ensure value is within 0-100
            $value = max( 0, min( 100, $value ) );
            
            $score += $value * $weight;
            $this->last_comfort_components[ $metric ] = [
                'value' => $value,
                'weight' => $weight,
                'contribution' => $value * $weight,
            ];
        }

        return round( $score, 1 );
    }

    /**
     * Calculate safety score (0-100)
     * 
     * Components:
     * - Crime Rate (40%): inverted (higher crime = lower safety)
     * - Infrastructure Index (35%): lighting, surveillance, emergency services
     * - Social Index (25%): community cohesion, activity
     */
    public function calculate_safety_score( array $metrics ): float {
        $weights = [
            'crime_rate' => 0.40,  // inverted
            'infrastructure_index' => 0.35,
            'social_index' => 0.25,
        ];

        $score = 0;
        $this->last_safety_components = [];

        foreach ( $weights as $metric => $weight ) {
            $value = $metrics[ $metric ] ?? 50;
            
            // Invert crime rate
            if ( $metric === 'crime_rate' ) {
                $value = 100 - $value;
            }
            
            $value = max( 0, min( 100, $value ) );
            
            $score += $value * $weight;
            $this->last_safety_components[ $metric ] = [
                'value' => $value,
                'weight' => $weight,
                'contribution' => $value * $weight,
            ];
        }

        return round( $score, 1 );
    }

    /**
     * Calculate functionality score (0-100)
     * 
     * Components:
     * - Infrastructure Index (30%): utilities, services
     * - Transport Index (30%): public transport, accessibility
     * - Economic Index (25%): jobs, commerce
     * - Social Index (15%): education, healthcare, shops
     */
    public function calculate_functionality_score( array $metrics ): float {
        $weights = [
            'infrastructure_index' => 0.30,
            'transport_index' => 0.30,
            'economic_index' => 0.25,
            'social_index' => 0.15,
        ];

        $score = 0;
        $this->last_functionality_components = [];

        foreach ( $weights as $metric => $weight ) {
            $value = $metrics[ $metric ] ?? 50;
            $value = max( 0, min( 100, $value ) );
            
            $score += $value * $weight;
            $this->last_functionality_components[ $metric ] = [
                'value' => $value,
                'weight' => $weight,
                'contribution' => $value * $weight,
            ];
        }

        return round( $score, 1 );
    }

    /**
     * Get overall district rating based on all scores
     */
    public function get_overall_rating( float $comfort, float $safety, float $functionality ): array {
        $average = ( $comfort + $safety + $functionality ) / 3;
        
        $rating = [
            'score' => round( $average, 1 ),
            'level' => $this->get_rating_level( $average ),
            'color' => $this->get_rating_color( $average ),
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
        if ( $score >= 80 ) return '#10b981'; // green
        if ( $score >= 60 ) return '#3b82f6'; // blue
        if ( $score >= 40 ) return '#f59e0b'; // orange
        if ( $score >= 20 ) return '#f97316'; // orange-red
        return '#ef4444'; // red
    }

    /**
     * Get last comfort components for detailed breakdown
     */
    public function get_last_comfort_components(): array {
        return $this->last_comfort_components;
    }

    /**
     * Get last safety components for detailed breakdown
     */
    public function get_last_safety_components(): array {
        return $this->last_safety_components;
    }

    /**
     * Get last functionality components for detailed breakdown
     */
    public function get_last_functionality_components(): array {
        return $this->last_functionality_components;
    }

    /**
     * Analyze district strengths and weaknesses
     */
    public function analyze_district( array $district ): array {
        $strengths = [];
        $weaknesses = [];

        $threshold_high = 70;
        $threshold_low = 40;

        // Check individual metrics
        $metrics_to_check = [
            'green_index' => 'Зеленые зоны',
            'infrastructure_index' => 'Инфраструктура',
            'transport_index' => 'Транспорт',
            'ecology_index' => 'Экология',
            'social_index' => 'Социальная сфера',
            'economic_index' => 'Экономика',
            'air_quality' => 'Качество воздуха',
        ];

        foreach ( $metrics_to_check as $key => $label ) {
            if ( isset( $district[ $key ] ) ) {
                $value = (float) $district[ $key ];
                if ( $value >= $threshold_high ) {
                    $strengths[] = $label;
                } elseif ( $value <= $threshold_low && $value > 0 ) {
                    $weaknesses[] = $label;
                }
            }
        }

        // Check crime rate (inverted)
        if ( isset( $district['crime_rate'] ) ) {
            $crime = (float) $district['crime_rate'];
            if ( $crime <= 20 ) {
                $strengths[] = 'Низкий уровень преступности';
            } elseif ( $crime >= 60 ) {
                $weaknesses[] = 'Высокий уровень преступности';
            }
        }

        // Check noise level (inverted)
        if ( isset( $district['noise_level'] ) ) {
            $noise = (float) $district['noise_level'];
            if ( $noise <= 30 ) {
                $strengths[] = 'Тихий район';
            } elseif ( $noise >= 70 ) {
                $weaknesses[] = 'Высокий уровень шума';
            }
        }

        // Check composite scores
        if ( $district['comfort_score'] >= $threshold_high ) {
            $strengths[] = 'Высокий комфорт проживания';
        } elseif ( $district['comfort_score'] <= $threshold_low && $district['comfort_score'] > 0 ) {
            $weaknesses[] = 'Низкий комфорт';
        }

        if ( $district['safety_score'] >= $threshold_high ) {
            $strengths[] = 'Безопасный район';
        } elseif ( $district['safety_score'] <= $threshold_low && $district['safety_score'] > 0 ) {
            $weaknesses[] = 'Проблемы с безопасностью';
        }

        if ( $district['functionality_score'] >= $threshold_high ) {
            $strengths[] = 'Высокая функциональность';
        } elseif ( $district['functionality_score'] <= $threshold_low && $district['functionality_score'] > 0 ) {
            $weaknesses[] = 'Слабая инфраструктура';
        }

        return [
            'strengths' => array_slice( $strengths, 0, 5 ),
            'weaknesses' => array_slice( $weaknesses, 0, 5 ),
        ];
    }
}