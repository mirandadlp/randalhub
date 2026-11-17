<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\JobSource;
use Illuminate\Database\Seeder;

/**
 * Starter watchlist. Every ATS board below was verified to exist and return open postings
 * via its public API on 2026-11-17. Employers without a documented public jobs API are added
 * with a disabled career-page source and a recorded limitation (nothing is scraped by default).
 */
class CompanyWatchlistSeeder extends Seeder
{
    /** [name, industry, ats, board identifier, website] */
    public const BOARDS = [
        // Artificial intelligence
        ['Anthropic', 'Artificial Intelligence', 'greenhouse', 'anthropic', 'https://www.anthropic.com'],
        ['OpenAI', 'Artificial Intelligence', 'ashby', 'openai', 'https://openai.com'],
        ['Cohere', 'Artificial Intelligence', 'ashby', 'cohere', 'https://cohere.com'],
        ['Perplexity', 'Artificial Intelligence', 'ashby', 'perplexity', 'https://www.perplexity.ai'],
        ['Scale AI', 'Artificial Intelligence', 'greenhouse', 'scaleai', 'https://scale.com'],
        ['Writer', 'Artificial Intelligence', 'ashby', 'writer', 'https://writer.com'],
        ['Harvey', 'Artificial Intelligence', 'ashby', 'harvey', 'https://www.harvey.ai'],
        ['Sierra', 'Artificial Intelligence', 'ashby', 'sierra', 'https://sierra.ai'],
        ['Decagon', 'Artificial Intelligence', 'ashby', 'decagon', 'https://decagon.ai'],
        ['Cresta', 'Artificial Intelligence', 'greenhouse', 'cresta', 'https://cresta.com'],
        ['Observe.AI', 'Artificial Intelligence', 'greenhouse', 'observeai', 'https://www.observe.ai'],
        ['AssemblyAI', 'Artificial Intelligence', 'greenhouse', 'assemblyai', 'https://www.assemblyai.com'],
        ['Deepgram', 'Artificial Intelligence', 'ashby', 'deepgram', 'https://deepgram.com'],
        ['ElevenLabs', 'Artificial Intelligence', 'ashby', 'elevenlabs', 'https://elevenlabs.io'],
        ['Synthesia', 'Artificial Intelligence', 'ashby', 'synthesia', 'https://www.synthesia.io'],
        ['Character.AI', 'Artificial Intelligence', 'ashby', 'character', 'https://character.ai'],
        ['Magic', 'Artificial Intelligence', 'ashby', 'magic', 'https://magic.dev'],
        ['Poolside', 'Artificial Intelligence', 'ashby', 'poolside', 'https://poolside.ai'],
        ['Cursor', 'Artificial Intelligence', 'ashby', 'cursor', 'https://cursor.com'],
        ['Replit', 'Artificial Intelligence', 'ashby', 'replit', 'https://replit.com'],
        ['Turing', 'Artificial Intelligence', 'greenhouse', 'turing', 'https://www.turing.com'],
        ['Databricks', 'Artificial Intelligence', 'greenhouse', 'databricks', 'https://www.databricks.com'],
        // Enterprise software / SaaS
        ['ServiceNow', 'Enterprise Software', 'smartrecruiters', 'ServiceNow', 'https://www.servicenow.com'],
        ['Snowflake', 'Cloud Computing', 'ashby', 'snowflake', 'https://www.snowflake.com'],
        ['Datadog', 'Cloud Computing', 'greenhouse', 'datadog', 'https://www.datadoghq.com'],
        ['MongoDB', 'Cloud Computing', 'greenhouse', 'mongodb', 'https://www.mongodb.com'],
        ['Elastic', 'Enterprise Software', 'greenhouse', 'elastic', 'https://www.elastic.co'],
        ['Cloudflare', 'Cloud Computing', 'greenhouse', 'cloudflare', 'https://www.cloudflare.com'],
        ['Twilio', 'Cloud Computing', 'greenhouse', 'twilio', 'https://www.twilio.com'],
        ['Okta', 'Enterprise Software', 'greenhouse', 'okta', 'https://www.okta.com'],
        ['GitLab', 'Enterprise Software', 'greenhouse', 'gitlab', 'https://about.gitlab.com'],
        ['Figma', 'SaaS', 'greenhouse', 'figma', 'https://www.figma.com'],
        ['Notion', 'SaaS', 'ashby', 'notion', 'https://www.notion.so'],
        ['Linear', 'SaaS', 'ashby', 'linear', 'https://linear.app'],
        ['Airtable', 'SaaS', 'greenhouse', 'airtable', 'https://www.airtable.com'],
        ['Asana', 'SaaS', 'greenhouse', 'asana', 'https://asana.com'],
        ['Canva', 'SaaS', 'smartrecruiters', 'Canva', 'https://www.canva.com'],
        ['Freshworks', 'SaaS', 'smartrecruiters', 'Freshworks', 'https://www.freshworks.com'],
        ['Miro', 'SaaS', 'ashby', 'miro', 'https://miro.com'],
        ['Dropbox', 'SaaS', 'greenhouse', 'dropbox', 'https://www.dropbox.com'],
        ['Calendly', 'SaaS', 'greenhouse', 'calendly', 'https://calendly.com'],
        ['Intercom', 'SaaS', 'greenhouse', 'intercom', 'https://www.intercom.com'],
        ['Outreach', 'SaaS', 'lever', 'outreach', 'https://www.outreach.io'],
        ['Salesloft', 'SaaS', 'greenhouse', 'salesloft', 'https://www.salesloft.com'],
        ['Gainsight', 'SaaS', 'ashby', 'gainsight', 'https://www.gainsight.com'],
        ['Vitally', 'SaaS', 'ashby', 'vitally', 'https://www.vitally.io'],
        ['Pendo', 'SaaS', 'greenhouse', 'pendo', 'https://www.pendo.io'],
        ['Amplitude', 'SaaS', 'ashby', 'amplitude', 'https://amplitude.com'],
        ['Mixpanel', 'SaaS', 'greenhouse', 'mixpanel', 'https://mixpanel.com'],
        ['Braze', 'SaaS', 'greenhouse', 'braze', 'https://www.braze.com'],
        ['Iterable', 'SaaS', 'ashby', 'iterable', 'https://iterable.com'],
        ['Klaviyo', 'SaaS', 'greenhouse', 'klaviyo', 'https://www.klaviyo.com'],
        ['Attentive', 'SaaS', 'greenhouse', 'attentive', 'https://www.attentive.com'],
        ['Samsara', 'Enterprise Software', 'greenhouse', 'samsara', 'https://www.samsara.com'],
        ['Gusto', 'SaaS', 'greenhouse', 'gusto', 'https://gusto.com'],
        ['Lattice', 'SaaS', 'greenhouse', 'lattice', 'https://lattice.com'],
        ['Culture Amp', 'SaaS', 'greenhouse', 'cultureamp', 'https://www.cultureamp.com'],
        ['Vanta', 'SaaS', 'ashby', 'vanta', 'https://www.vanta.com'],
        ['Drata', 'SaaS', 'ashby', 'drata', 'https://drata.com'],
        ['Secureframe', 'SaaS', 'ashby', 'secureframe', 'https://secureframe.com'],
        ['Snyk', 'Enterprise Software', 'ashby', 'snyk', 'https://snyk.io'],
        ['Sumo Logic', 'Cloud Computing', 'greenhouse', 'sumologic', 'https://www.sumologic.com'],
        ['New Relic', 'Cloud Computing', 'greenhouse', 'newrelic', 'https://newrelic.com'],
        ['PagerDuty', 'Cloud Computing', 'greenhouse', 'pagerduty', 'https://www.pagerduty.com'],
        ['LaunchDarkly', 'SaaS', 'greenhouse', 'launchdarkly', 'https://launchdarkly.com'],
        ['CircleCI', 'Cloud Computing', 'greenhouse', 'circleci', 'https://circleci.com'],
        ['Vercel', 'Cloud Computing', 'greenhouse', 'vercel', 'https://vercel.com'],
        ['Netlify', 'Cloud Computing', 'greenhouse', 'netlify', 'https://www.netlify.com'],
        ['Supabase', 'Cloud Computing', 'ashby', 'supabase', 'https://supabase.com'],
        ['Sentry', 'Cloud Computing', 'ashby', 'sentry', 'https://sentry.io'],
        ['Fivetran', 'Cloud Computing', 'greenhouse', 'fivetran', 'https://www.fivetran.com'],
        ['Airbyte', 'Cloud Computing', 'ashby', 'airbyte', 'https://airbyte.com'],
        ['NielsenIQ', 'Enterprise Software', 'smartrecruiters', 'NielsenIQ', 'https://nielseniq.com'],
        // Business automation
        ['Zapier', 'Business Automation', 'ashby', 'zapier', 'https://zapier.com'],
        ['Workato', 'Business Automation', 'greenhouse', 'workato', 'https://www.workato.com'],
        ['Celonis', 'Business Automation', 'greenhouse', 'celonis', 'https://www.celonis.com'],
        ['UiPath', 'Business Automation', 'ashby', 'uipath', 'https://www.uipath.com'],
        // Fintech / banking / mortgage
        ['Stripe', 'Fintech', 'greenhouse', 'stripe', 'https://stripe.com'],
        ['Plaid', 'Fintech', 'ashby', 'plaid', 'https://plaid.com'],
        ['Brex', 'Fintech', 'greenhouse', 'brex', 'https://www.brex.com'],
        ['Ramp', 'Fintech', 'ashby', 'ramp', 'https://ramp.com'],
        ['Affirm', 'Fintech', 'greenhouse', 'affirm', 'https://www.affirm.com'],
        ['Chime', 'Fintech', 'greenhouse', 'chime', 'https://www.chime.com'],
        ['SoFi', 'Banking & Financial Services', 'greenhouse', 'sofi', 'https://www.sofi.com'],
        ['Robinhood', 'Fintech', 'greenhouse', 'robinhood', 'https://robinhood.com'],
        ['Coinbase', 'Fintech', 'greenhouse', 'coinbase', 'https://www.coinbase.com'],
        ['Mercury', 'Banking & Financial Services', 'greenhouse', 'mercury', 'https://mercury.com'],
        ['Betterment', 'Banking & Financial Services', 'greenhouse', 'betterment', 'https://www.betterment.com'],
        ['Wealthfront', 'Banking & Financial Services', 'lever', 'wealthfront', 'https://www.wealthfront.com'],
        ['Carta', 'Fintech', 'greenhouse', 'carta', 'https://carta.com'],
        ['Experian', 'Banking & Financial Services', 'smartrecruiters', 'Experian', 'https://www.experian.com'],
        ['Blend', 'Mortgage & Lending', 'greenhouse', 'blend', 'https://blend.com'],
        ['Maxwell', 'Mortgage & Lending', 'greenhouse', 'maxwell', 'https://himaxwell.com'],
        ['Snapdocs', 'Mortgage & Lending', 'ashby', 'snapdocs', 'https://www.snapdocs.com'],
        // Insurance
        ['Lemonade', 'Insurance', 'ashby', 'lemonade', 'https://www.lemonade.com'],
        ['Openly', 'Insurance', 'ashby', 'openly', 'https://openly.com'],
        ['Coalition', 'Insurance', 'greenhouse', 'coalition', 'https://www.coalitioninc.com'],
        ['Oscar Health', 'Insurance', 'greenhouse', 'oscar', 'https://www.hioscar.com'],
        // Healthcare technology
        ['Headway', 'Healthcare Technology', 'ashby', 'headway', 'https://headway.co'],
        ['Lyra Health', 'Healthcare Technology', 'lever', 'lyrahealth', 'https://www.lyrahealth.com'],
        ['Commure', 'Healthcare Technology', 'ashby', 'commure', 'https://www.commure.com'],
        ['Abridge', 'Healthcare Technology', 'ashby', 'abridge', 'https://www.abridge.com'],
        ['Zocdoc', 'Healthcare Technology', 'greenhouse', 'zocdoc', 'https://www.zocdoc.com'],
        ['Doximity', 'Healthcare Technology', 'greenhouse', 'doximity', 'https://www.doximity.com'],
        ['Veeva Systems', 'Healthcare Technology', 'lever', 'veeva', 'https://www.veeva.com'],
        ['Ro', 'Healthcare Technology', 'lever', 'ro', 'https://ro.co'],
        // Hospitality technology
        ['Cloudbeds', 'Hospitality Technology', 'greenhouse', 'cloudbeds', 'https://www.cloudbeds.com'],
        ['SiteMinder', 'Hospitality Technology', 'ashby', 'SiteMinder', 'https://www.siteminder.com'],
        ['Breezeway', 'Hospitality Technology', 'greenhouse', 'breezeway', 'https://www.breezeway.io'],
        ['Apaleo', 'Hospitality Technology', 'greenhouse', 'apaleo', 'https://apaleo.com'],
        ['Lodgify', 'Hospitality Technology', 'lever', 'lodgify', 'https://www.lodgify.com'],
        ['Toast', 'Hospitality Technology', 'greenhouse', 'toast', 'https://pos.toasttab.com'],
        ['Accor', 'Hospitality Technology', 'smartrecruiters', 'Accor', 'https://group.accor.com'],
        ['Sodexo', 'Hospitality Technology', 'smartrecruiters', 'Sodexo', 'https://www.sodexo.com'],
        ['Navan', 'Hospitality Technology', 'greenhouse', 'tripactions', 'https://navan.com'],
        // Real estate technology
        ['AppFolio', 'Real Estate Technology', 'ashby', 'appfolio', 'https://www.appfolio.com'],
        ['Entrata', 'Real Estate Technology', 'lever', 'entrata', 'https://www.entrata.com'],
        ['HomeLight', 'Real Estate Technology', 'greenhouse', 'homelight', 'https://www.homelight.com'],
        ['Colliers', 'Real Estate Technology', 'smartrecruiters', 'Colliers', 'https://www.colliers.com'],
        ['Airbnb', 'Hospitality Technology', 'greenhouse', 'airbnb', 'https://www.airbnb.com'],
        // Consulting
        ['Thoughtworks', 'Consulting', 'greenhouse', 'thoughtworks', 'https://www.thoughtworks.com'],
        ['Andela', 'Consulting', 'ashby', 'andela', 'https://andela.com'],
        ['Palantir', 'Enterprise Software', 'lever', 'palantir', 'https://www.palantir.com'],
        // Consumer / big tech-adjacent
        ['Spotify', 'Big Tech', 'lever', 'spotify', 'https://www.spotify.com'],
        ['Pinterest', 'Big Tech', 'greenhouse', 'pinterest', 'https://www.pinterest.com'],
        ['Reddit', 'Big Tech', 'greenhouse', 'reddit', 'https://www.reddit.com'],
        ['Lyft', 'Big Tech', 'greenhouse', 'lyft', 'https://www.lyft.com'],
        ['Instacart', 'Big Tech', 'greenhouse', 'instacart', 'https://www.instacart.com'],
        ['Discord', 'Big Tech', 'greenhouse', 'discord', 'https://discord.com'],
        ['Duolingo', 'Big Tech', 'greenhouse', 'duolingo', 'https://www.duolingo.com'],
        ['Coursera', 'SaaS', 'greenhouse', 'coursera', 'https://www.coursera.org'],
        ['Guild', 'Startups', 'greenhouse', 'guild', 'https://www.guild.com'],
        ['Hugging Face', 'Artificial Intelligence', 'workable', 'huggingface', 'https://huggingface.co'],
    ];

    /** Employers without a documented public jobs API. Sources are created disabled. */
    public const CAREER_SITES = [
        ['Google', 'Big Tech', 'https://www.google.com/about/careers/applications/jobs/results/'],
        ['Meta', 'Big Tech', 'https://www.metacareers.com/jobs'],
        ['Microsoft', 'Big Tech', 'https://jobs.careers.microsoft.com/global/en/search'],
        ['Amazon', 'Big Tech', 'https://www.amazon.jobs/en/search'],
        ['Salesforce', 'Enterprise Software', 'https://careers.salesforce.com/en/jobs/'],
        ['Oracle', 'Enterprise Software', 'https://careers.oracle.com/jobs/'],
        ['Adobe', 'Enterprise Software', 'https://careers.adobe.com/us/en/search-results'],
        ['IBM', 'Consulting', 'https://www.ibm.com/careers/search'],
        ['Accenture', 'Consulting', 'https://www.accenture.com/us-en/careers/jobsearch'],
        ['Deloitte', 'Consulting', 'https://apply.deloitte.com/careers/SearchJobs'],
    ];

    public function run(): void
    {
        foreach (self::BOARDS as [$name, $industry, $type, $identifier, $website]) {
            $company = Company::firstOrCreate(
                ['name' => $name],
                ['industry' => $industry, 'website' => $website, 'is_watched' => true, 'discovered_via' => 'seed'],
            );
            JobSource::firstOrCreate(
                ['type' => $type, 'identifier' => $identifier],
                ['company_id' => $company->id, 'name' => $name.' ('.ucfirst($type).')', 'enabled' => true],
            );
        }

        foreach (self::CAREER_SITES as [$name, $industry, $url]) {
            $company = Company::firstOrCreate(
                ['name' => $name],
                ['industry' => $industry, 'careers_url' => $url, 'is_watched' => true, 'discovered_via' => 'seed'],
            );
            JobSource::firstOrCreate(
                ['type' => 'career_page', 'identifier' => $url],
                [
                    'company_id' => $company->id,
                    'name' => $name.' careers site',
                    'enabled' => false,
                    'health' => 'unsupported',
                    'limitation_note' => 'No documented public jobs API. This career site loads listings with JavaScript, '
                        .'so structured collection is not available. Enable to attempt schema.org JobPosting extraction '
                        .'(robots.txt is respected), or cover this employer through a job search API such as Adzuna.',
                ],
            );
        }

        // Keyword searches via Adzuna (aggregator): enabled once ADZUNA_APP_ID/KEY are configured.
        foreach (['AI consultant', 'implementation consultant', 'customer onboarding manager', 'AI enablement', 'hospitality technology'] as $query) {
            JobSource::firstOrCreate(
                ['type' => 'adzuna', 'identifier' => $query],
                [
                    'name' => 'Adzuna search: '.$query,
                    'enabled' => false,
                    'config' => ['max_days_old' => 14, 'pages' => 2],
                    'limitation_note' => 'Requires Adzuna API credentials (ADZUNA_APP_ID / ADZUNA_APP_KEY).',
                ],
            );
        }
    }
}
