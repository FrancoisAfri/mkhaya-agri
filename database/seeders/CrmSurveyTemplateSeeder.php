<?php

namespace Database\Seeders;

use App\Modules\CRM\Models\CrmSurveyTemplate;
use Illuminate\Database\Seeder;

/**
 * Best-practice customer survey templates, loaded on install. Users pick one as
 * the starting point for a customer survey. Question types: nps (0-10),
 * csat (1-5), rating (1-5), scale (1-7), choice, multichoice, text.
 */
class CrmSurveyTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            CrmSurveyTemplate::updateOrCreate(['key' => $tpl['key']], $tpl);
        }
    }

    private function q(int $id, string $type, string $label, bool $required = true, array $options = []): array
    {
        $q = ['id' => $id, 'type' => $type, 'label' => $label, 'required' => $required];
        if ($options) $q['options'] = $options;
        return $q;
    }

    private function templates(): array
    {
        return [
            [
                'key' => 'nps', 'name' => 'Net Promoter Score (NPS)', 'category' => 'loyalty',
                'description' => 'The global standard for measuring customer loyalty and word-of-mouth.',
                'questions' => [
                    $this->q(1, 'nps', 'How likely are you to recommend us to a friend or colleague?'),
                    $this->q(2, 'text', 'What is the main reason for your score?'),
                    $this->q(3, 'text', 'What is the one thing we could do to improve?', false),
                ],
            ],
            [
                'key' => 'csat', 'name' => 'Customer Satisfaction (CSAT)', 'category' => 'satisfaction',
                'description' => 'A quick pulse on how satisfied customers are with your product or service.',
                'questions' => [
                    $this->q(1, 'csat', 'Overall, how satisfied are you with us?'),
                    $this->q(2, 'rating', 'How satisfied are you with the quality of our product/service?'),
                    $this->q(3, 'rating', 'How satisfied are you with the value for money?'),
                    $this->q(4, 'text', 'What could we do better?', false),
                ],
            ],
            [
                'key' => 'ces', 'name' => 'Customer Effort Score (CES)', 'category' => 'support',
                'description' => 'Measures how easy you make it for customers to do business with you.',
                'questions' => [
                    $this->q(1, 'scale', 'How easy was it to get your issue resolved?', true),
                    $this->q(2, 'choice', 'Where did you experience the most effort?', false, ['Finding information', 'Contacting us', 'Waiting for a response', 'Getting a resolution', 'None']),
                    $this->q(3, 'text', 'Tell us more about your experience.', false),
                ],
            ],
            [
                'key' => 'support', 'name' => 'Support Interaction Feedback', 'category' => 'support',
                'description' => 'Sent after a support ticket or call to rate the interaction.',
                'questions' => [
                    $this->q(1, 'csat', 'How satisfied are you with the support you received?'),
                    $this->q(2, 'rating', 'How knowledgeable was the person who helped you?'),
                    $this->q(3, 'rating', 'How quickly was your issue resolved?'),
                    $this->q(4, 'choice', 'Was your issue resolved?', true, ['Yes, fully', 'Partly', 'No']),
                    $this->q(5, 'text', 'Any additional comments?', false),
                ],
            ],
            [
                'key' => 'onboarding', 'name' => 'Onboarding Experience', 'category' => 'lifecycle',
                'description' => 'Capture how new customers experienced getting started with you.',
                'questions' => [
                    $this->q(1, 'csat', 'How satisfied are you with your onboarding experience?'),
                    $this->q(2, 'rating', 'How clear was the onboarding process?'),
                    $this->q(3, 'rating', 'How well did we set expectations?'),
                    $this->q(4, 'text', 'What would have made onboarding smoother?', false),
                ],
            ],
            [
                'key' => 'product', 'name' => 'Product Feedback', 'category' => 'product',
                'description' => 'Understand satisfaction with your product and what to build next.',
                'questions' => [
                    $this->q(1, 'csat', 'How satisfied are you with our product overall?'),
                    $this->q(2, 'choice', 'How often do you use our product?', true, ['Daily', 'Weekly', 'Monthly', 'Rarely']),
                    $this->q(3, 'multichoice', 'Which areas need the most improvement?', false, ['Features', 'Ease of use', 'Performance/Reliability', 'Pricing', 'Support', 'Documentation']),
                    $this->q(4, 'text', 'What feature would you most like to see?', false),
                ],
            ],
            [
                'key' => 'account_review', 'name' => 'Account Health / Relationship Review', 'category' => 'account',
                'description' => 'A relationship pulse for key accounts to spot churn risk early.',
                'questions' => [
                    $this->q(1, 'nps', 'How likely are you to continue working with us?'),
                    $this->q(2, 'rating', 'How well do we understand your business needs?'),
                    $this->q(3, 'rating', 'How responsive is your account team?'),
                    $this->q(4, 'rating', 'How much value are you getting from the partnership?'),
                    $this->q(5, 'text', 'What is the most important thing we could do for you this year?', false),
                ],
            ],
            [
                'key' => 'renewal', 'name' => 'Renewal / Churn Risk', 'category' => 'lifecycle',
                'description' => 'Gauge likelihood to renew ahead of contract end.',
                'questions' => [
                    $this->q(1, 'scale', 'How likely are you to renew with us?'),
                    $this->q(2, 'choice', 'What best describes your current view?', true, ['Definitely renewing', 'Likely renewing', 'Undecided', 'Considering alternatives', 'Likely leaving']),
                    $this->q(3, 'text', 'What would make renewing an easy decision?', false),
                ],
            ],
            [
                'key' => 'winloss', 'name' => 'Post-Purchase (Win) Feedback', 'category' => 'sales',
                'description' => 'Learn why customers chose you to sharpen your sales motion.',
                'questions' => [
                    $this->q(1, 'multichoice', 'What were the main reasons you chose us?', true, ['Price', 'Features', 'Reputation', 'Recommendation', 'Support', 'Ease of use', 'Sales experience']),
                    $this->q(2, 'rating', 'How was your buying experience?'),
                    $this->q(3, 'text', 'What nearly stopped you from buying?', false),
                ],
            ],
            [
                'key' => 'service_delivery', 'name' => 'Service Delivery Quality', 'category' => 'service',
                'description' => 'Sent after a project or service delivery milestone.',
                'questions' => [
                    $this->q(1, 'csat', 'How satisfied are you with the delivery?'),
                    $this->q(2, 'rating', 'Was the work delivered on time?'),
                    $this->q(3, 'rating', 'Did it meet the agreed quality and scope?'),
                    $this->q(4, 'text', 'What stood out — good or bad?', false),
                ],
            ],
        ];
    }
}
