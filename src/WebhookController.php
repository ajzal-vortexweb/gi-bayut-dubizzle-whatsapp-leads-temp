<?php
require_once __DIR__ . "/../crest/crest.php";
require_once __DIR__ . "/../utils.php";

define('CONFIG', require_once __DIR__ . '/../config.php');

class WebhookController
{
    private const ALLOWED_ROUTES = [
        'bayut-whatsapp' => 'handleBayutWhatsapp',
        'dubizzle-whatsapp' => 'handleDubizzleWhatsapp',
    ];

    private LoggerController $logger;
    private BitrixController $bitrix;

    public function __construct()
    {
        $this->logger = new LoggerController();
        $this->bitrix = new BitrixController();
    }

    // Handles incoming webhooks
    public function handleRequest(string $route): void
    {
        try {
            $this->logger->logRequest($route);

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->sendResponse(405, [
                    'error' => 'Method Not Allowed. Only POST is accepted.'
                ]);
            }

            if (!array_key_exists($route, self::ALLOWED_ROUTES)) {
                $this->sendResponse(404, [
                    'error' => 'Resource not found'
                ]);
            }

            $handlerMethod = self::ALLOWED_ROUTES[$route];

            $data = $this->parseRequestData();
            if ($data === null) {
                $this->sendResponse(400, [
                    'error' => 'Invalid JSON data'
                ]);
            }

            $this->$handlerMethod($data);
        } catch (Throwable $e) {
            $this->logger->logError('Error processing request', $e);
            $this->sendResponse(500, [
                'error' => 'Internal server error'
            ]);
        }
    }

    // Parses incoming JSON data
    private function parseRequestData(): ?array
    {
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        return $data;
    }

    // Sends response back to the webhook
    private function sendResponse(int $statusCode, array $data): void
    {
        header("Content-Type: application/json");
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }

    // Handles bayut-whatsapp webhook event
    public function handleBayutWhatsapp(array $data): void
    {
        $this->logger->logWebhook('bayut-whatsapp', $data);

        $reference = $data['listing']['reference'] ?? '';
        $listingData = !empty($reference) ? getListingData($reference) : ['owner' => 'N/A', 'community' => 'N/A', 'price' => null];

        $title = "Bayut - WhatsApp - " . ($reference !== "" ? $reference : 'No reference');

        // Map fields using the Temp CRM field IDs
        $fields = [
            'TITLE' => $title,
            'CATEGORY_ID' => CONFIG['SECONDARY_PIPELINE_ID'],
            'SOURCE_ID' => CONFIG['BAYUT_WHATSAPP'],
            // Map Owner Name to the specific text field
            CONFIG['RESPONSIBLE_NAME_FIELD'] => $listingData['owner'],
            // Client and Property Fields (matching PF Integration turn IDs)
            'UF_CRM_1741123034066' => $data['enquirer']['name'] ?? 'Unknown', // Name
            'UF_CRM_1741126758' => $data['enquirer']['phone_number'],        // Phone
            'UF_CRM_1739873044322' => $data['enquirer']['contact_link'],      // Tracking Link
            'UF_CRM_1739890146108' => $reference,                             // Reference
            'UF_CRM_1739945676' => $data['listing']['url'],                   // Property Link
            'OPPORTUNITY' => $listingData['price'],
            'COMMENTS' => "Community: " . $listingData['community'] . "\nMessage: " . ($data['message'] ?? ''),
            'CONTACT_ID' => 0, // Skipping contact creation
        ];

        $leadId = $this->bitrix->addLead($fields);
        $this->logger->logFields('bayut-whatsapp', [...$fields, 'ID' => $leadId]);

        $this->sendResponse(200, [
            'message' => 'Processed for temp crm. Deal created with ID: ' . $leadId,
        ]);
    }

    // Handles dubizzle-whatsapp webhook event (similar logic)
    public function handleDubizzleWhatsapp(array $data): void
    {
        $this->logger->logWebhook('dubizzle-whatsapp', $data);

        $reference = $data['listing']['reference'] ?? '';
        $listingData = !empty($reference) ? getListingData($reference) : ['owner' => 'N/A', 'community' => 'N/A', 'price' => null];

        $title = "Dubizzle - WhatsApp - " . ($reference !== "" ? $reference : 'No reference');

        $fields = [
            'TITLE' => $title,
            'CATEGORY_ID' => CONFIG['SECONDARY_PIPELINE_ID'],
            'SOURCE_ID' => CONFIG['DUBIZZLE_WHATSAPP'],
            CONFIG['RESPONSIBLE_NAME_FIELD'] => $listingData['owner'],
            'UF_CRM_1741123034066' => $data['enquirer']['name'] ?? 'Unknown',
            'UF_CRM_1741126758' => $data['enquirer']['phone_number'],
            'UF_CRM_1739873044322' => $data['enquirer']['contact_link'],
            'UF_CRM_1739890146108' => $reference,
            'UF_CRM_1739945676' => $data['listing']['url'],
            'OPPORTUNITY' => $listingData['price'],
            'COMMENTS' => "Community: " . $listingData['community'],
            'CONTACT_ID' => 0,
        ];

        $leadId = $this->bitrix->addLead($fields);
        $this->logger->logFields('dubizzle-whatsapp', [...$fields, 'ID' => $leadId]);

        $this->sendResponse(200, [
            'message' => 'Processed for temp crm. Deal created with ID: ' . $leadId,
        ]);
    }
}
