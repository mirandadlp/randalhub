<?php

namespace App\Services\Support;

class LocationClassifier
{
    private const US_STATES = [
        'AL' => 'alabama', 'AK' => 'alaska', 'AZ' => 'arizona', 'AR' => 'arkansas', 'CA' => 'california',
        'CO' => 'colorado', 'CT' => 'connecticut', 'DE' => 'delaware', 'FL' => 'florida', 'GA' => 'georgia',
        'HI' => 'hawaii', 'ID' => 'idaho', 'IL' => 'illinois', 'IN' => 'indiana', 'IA' => 'iowa', 'KS' => 'kansas',
        'KY' => 'kentucky', 'LA' => 'louisiana', 'ME' => 'maine', 'MD' => 'maryland', 'MA' => 'massachusetts',
        'MI' => 'michigan', 'MN' => 'minnesota', 'MS' => 'mississippi', 'MO' => 'missouri', 'MT' => 'montana',
        'NE' => 'nebraska', 'NV' => 'nevada', 'NH' => 'new hampshire', 'NJ' => 'new jersey', 'NM' => 'new mexico',
        'NY' => 'new york', 'NC' => 'north carolina', 'ND' => 'north dakota', 'OH' => 'ohio', 'OK' => 'oklahoma',
        'OR' => 'oregon', 'PA' => 'pennsylvania', 'RI' => 'rhode island', 'SC' => 'south carolina',
        'SD' => 'south dakota', 'TN' => 'tennessee', 'TX' => 'texas', 'UT' => 'utah', 'VT' => 'vermont',
        'VA' => 'virginia', 'WA' => 'washington', 'WV' => 'west virginia', 'WI' => 'wisconsin', 'WY' => 'wyoming',
        'DC' => 'district of columbia',
    ];

    private const US_CITIES = [
        'san francisco', 'new york', 'nyc', 'seattle', 'austin', 'boston', 'chicago', 'los angeles', 'denver',
        'atlanta', 'miami', 'dallas', 'houston', 'las vegas', 'henderson', 'reno', 'phoenix', 'san diego',
        'san jose', 'palo alto', 'mountain view', 'menlo park', 'sunnyvale', 'washington dc', 'portland',
        'salt lake city', 'detroit', 'philadelphia', 'pittsburgh', 'charlotte', 'nashville', 'raleigh',
        'minneapolis', 'kansas city', 'st. louis', 'columbus', 'irvine', 'oakland', 'redwood city', 'bellevue',
        'scottsdale', 'tampa', 'orlando', 'brooklyn', 'cambridge, ma', 'baltimore',
    ];

    private const NON_US = [
        'canada', 'toronto', 'vancouver', 'montreal', 'united kingdom', 'uk', 'london', 'england', 'ireland',
        'dublin', 'germany', 'berlin', 'munich', 'france', 'paris', 'spain', 'madrid', 'barcelona', 'netherlands',
        'amsterdam', 'india', 'bangalore', 'bengaluru', 'hyderabad', 'singapore', 'japan', 'tokyo', 'australia',
        'sydney', 'melbourne', 'brazil', 'sao paulo', 'mexico', 'poland', 'warsaw', 'israel', 'tel aviv',
        'switzerland', 'zurich', 'sweden', 'stockholm', 'korea', 'seoul', 'emea', 'apac', 'latam', 'europe',
        'philippines', 'argentina', 'colombia', 'portugal', 'lisbon', 'italy', 'denmark', 'norway', 'finland',
        'belgium', 'austria', 'czech', 'romania', 'china', 'hong kong', 'taiwan', 'new zealand', 'south africa',
        'uae', 'dubai', 'saudi', 'costa rica', 'chile', 'peru', 'nigeria', 'kenya', 'egypt', 'turkey', 'greece',
    ];

    /**
     * @return array{remote_type: string, is_us: ?bool, is_nevada: bool}
     */
    public static function classify(?string $location, ?string $workplaceHint = null, ?bool $remoteFlag = null, ?string $country = null): array
    {
        $loc = ' '.strtolower((string) $location).' ';
        $hint = strtolower((string) $workplaceHint);

        $remoteType = 'unknown';
        if (str_contains($hint, 'hybrid') || str_contains($loc, 'hybrid')) {
            $remoteType = 'hybrid';
        } elseif (str_contains($hint, 'remote') || $remoteFlag === true || preg_match('/\b(remote|anywhere|distributed|work from home|wfh)\b/', $loc)) {
            $remoteType = 'remote';
        } elseif (str_contains($hint, 'on-site') || str_contains($hint, 'onsite') || str_contains($hint, 'office') || str_contains($hint, 'in person')) {
            $remoteType = 'onsite';
        } elseif (trim($loc) !== '' && ! preg_match('/remote/', $loc)) {
            $remoteType = 'onsite';
        }

        $isUs = null;
        $countryL = strtolower((string) $country);
        if ($countryL !== '') {
            $isUs = in_array($countryL, ['us', 'usa', 'united states', 'united states of america'], true);
        }
        if ($isUs === null || $isUs === false) {
            if (preg_match('/\b(united states|usa|u\.s\.a?\.?|us-remote|remote[ ,\-–(]+us\b|us remote|\(us\)|, us\b|north america|americas)\b/i', $loc)) {
                $isUs = true;
            }
        }
        if ($isUs === null) {
            foreach (self::US_STATES as $abbr => $name) {
                if (preg_match('/[ ,(]'.strtolower($abbr).'[ ,)]/', $loc) || str_contains($loc, ' '.$name.' ') || str_contains($loc, $name.',')) {
                    $isUs = true;
                    break;
                }
            }
        }
        if ($isUs === null) {
            foreach (self::US_CITIES as $city) {
                if (str_contains($loc, $city)) {
                    $isUs = true;
                    break;
                }
            }
        }
        if ($isUs === null) {
            foreach (self::NON_US as $place) {
                if (preg_match('/\b'.preg_quote($place, '/').'\b/', $loc)) {
                    $isUs = false;
                    break;
                }
            }
        }

        $isNevada = (bool) preg_match('/\b(las vegas|henderson|reno|nevada|, nv\b| nv )/', $loc);
        if ($isNevada) {
            $isUs = true;
        }

        return ['remote_type' => $remoteType, 'is_us' => $isUs, 'is_nevada' => $isNevada];
    }
}
