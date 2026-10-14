<?php
namespace ISF\Connectors\DominionPtr;

if (!defined('ABSPATH')) { exit; }

/**
 * Server-only request planner for the account-based PTR flow.
 * Makes NO HTTP requests. Keep plans and vendor tokens out of browser responses.
 * The existing live submit_enrollment gate remains unchanged.
 */
final class AccountEnrollmentFlow {
    private string $state = 'validated';
    private array $customer;
    private array $premises;
    private array $session = [];
    private array $survey = [];
    private array $enrollment = [];
    private ?string $uuid = null;
    private ?array $premise = null;

    public function __construct(array $validated) {
        if (($validated['status'] ?? '') !== 'found' || !is_array($validated['data']['enrollable_premises'] ?? null)) {
            throw new \InvalidArgumentException('A successful validation is required.');
        }
        $this->customer = $validated['data'];
        $this->premises = $this->customer['enrollable_premises'];
    }

    public function status(): string { return $this->state; }

    public function invalidate(): void {
        $this->state = 'invalidated';
        $this->customer = $this->premises = $this->session = $this->survey = $this->enrollment = [];
        $this->premise = null;
        $this->uuid = null;
    }

    public function confirm_premise(string $id, string $email): array {
        $this->require_state('validated');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('A valid contact email is required.');
        foreach ($this->premises as $premise) {
            if ((string)($premise['id'] ?? '') === $id) $this->premise = $premise;
        }
        if (!$this->premise || empty($this->premise['zip']) || empty($this->customer['utility_no'])) {
            throw new \InvalidArgumentException('Select a premise returned by validation.');
        }
        $this->state = 'premise_confirmed';
        return ['method'=>'POST','path'=>'portal_user/create_from_prospect','data'=>[
            'premise_id'=>$this->premise['id'], 'zip'=>$this->premise['zip'],
            'preferred_format'=>'json', 'utility_no'=>$this->customer['utility_no'],
            'email'=>$email, 'first_name'=>$this->customer['first_name'] ?? '',
            'last_name'=>$this->customer['last_name'] ?? '',
            'mobile_telephone'=>$this->customer['mobile_telephone'] ?? '',
        ],'headers'=>[]];
    }

    public function accept_session(array $response): void {
        $this->require_state('premise_confirmed');
        $user=$response['portal_user'] ?? [];
        if (!is_scalar($user['id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{1,128}$/D',(string)$user['id']) || !is_string($user['enrollment_token'] ?? null) || $user['enrollment_token']==='' || preg_match('/[\r\n]/',$user['enrollment_token'])) {
            throw new \UnexpectedValueException('Enrollment session was not established.');
        }
        $this->session=['X-Enrollment-Token'=>$user['enrollment_token'],'X-PortalUser-Id'=>(string)$user['id'],'X-PortalUser-Premise'=>(string)$this->premise['id']];
        $this->state='session_ready';
    }

    public function qualification_request(string $key = 'enrollment_qualification'): array {
        $this->require_state('session_ready');
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$key)) throw new \InvalidArgumentException('Invalid survey key.');
        return $this->request('GET','single_page_premise_surveys/by_key/'.$key);
    }

    public function accept_qualification(array $survey): void {
        $this->require_state('session_ready');
        if (!is_scalar($survey['id'] ?? null) || !is_array($survey['categories'] ?? null)) throw new \UnexpectedValueException('Qualification survey is unavailable.');
        $this->survey=$survey;$this->state='qualification_ready';
    }

    /**
     * Accept server-validated survey answers. Preserve the server's schema;
     * program ids and terms MUST NOT be invented or automatically accepted.
     * Caller must apply conditional question rules supplied by the portal.
     */
    public function qualification_completion_request(array $answered_survey): array {
        $this->require_state('qualification_ready');
        if (($answered_survey['id'] ?? null) !== $this->survey['id']) throw new \InvalidArgumentException('Survey identity changed.');
        $expected=[];
        foreach ($this->survey['categories'] as $category) foreach ($category['questions'] ?? [] as $question) $expected[(string)$question['id']]=$question;
        $seen=[];
        foreach ($answered_survey['categories'] ?? [] as $category) foreach ($category['questions'] ?? [] as $question) {
            $id=(string)($question['id'] ?? '');
            if (!isset($expected[$id]) || isset($seen[$id])) throw new \InvalidArgumentException('Unexpected survey question.');
            $original=$expected[$id];$seen[$id]=true;$answered=false;
            $allowed=[];foreach ($original['answers'] ?? [] as $answer) $allowed[(string)$answer['id']]=$answer;
            foreach ($question['answers'] ?? [] as $answer) {
                if (($original['control_type'] ?? '') !== 'text' && !isset($allowed[(string)($answer['id'] ?? '')])) throw new \InvalidArgumentException('Unexpected survey answer.');
                if (isset($answer['value']) && !is_bool($answer['value'])) throw new \InvalidArgumentException('Survey answer flags must be boolean.');
                if (($original['control_type'] ?? '') === 'text' && ($answer['value'] ?? false) === true && trim((string)($answer['id'] ?? '')) === '') throw new \InvalidArgumentException('Required text is empty.');
                if (($answer['value'] ?? false) === true) {
                    $answered=true;
                    if (!empty($allowed[(string)($answer['id'] ?? '')]['failure_message'])) throw new \InvalidArgumentException('Qualification requirement was not met.');
                }
            }
            if (!empty($original['mandatory']) && !$answered) throw new \InvalidArgumentException('Required question is unanswered.');
        }
        foreach ($expected as $id=>$question) if (!empty($question['mandatory']) && !isset($seen[$id])) throw new \InvalidArgumentException('Required question is missing.');
        $this->state='qualification_pending';
        return $this->request('POST','single_page_premise_surveys/'.rawurlencode((string)$this->survey['id']).'/complete',$answered_survey);
    }

    public function accept_qualification_completion(array $response): void {
        $this->require_state('qualification_pending');
        $uuid=$response['data']['uuid'] ?? null;
        if (($response['success'] ?? null)!==true || !is_string($uuid) || !preg_match('/^[a-zA-Z0-9-]{1,128}$/D',$uuid)) throw new \UnexpectedValueException('Qualification did not succeed.');
        if (!empty($response['data']['bring_your_own_device'])) throw new \UnexpectedValueException('Device enrollment needs a separate supported flow.');
        $this->uuid=$uuid;$this->state='qualified';
    }

    public function enrollment_request(): array {
        $this->require_state('qualified');
        return $this->request('GET','enrollments/'.$this->uuid);
    }

    public function accept_enrollment(array $model): void {
        $this->require_state('qualified');
        if ((string)($model['id'] ?? '') !== $this->uuid) throw new \UnexpectedValueException('Enrollment identity changed.');
        $this->enrollment=$model;$this->state='enrollment_ready';
    }

    /** Draft only: method/body still require Itron confirmation before wiring. */
    public function enrollment_submission_plan(): array {
        $this->require_state('enrollment_ready');
        $this->state='enrollment_pending';
        return $this->request('PUT','enrollments/'.$this->uuid,$this->enrollment)+['requires_vendor_confirmation'=>true];
    }

    public function accept_enrollment_completion(array $response): void {
        $this->require_state('enrollment_pending');
        if (($response['success'] ?? null)!==true) { $this->state='enrollment_ready'; throw new \UnexpectedValueException('Enrollment did not succeed.'); }
        $this->session=[];$this->state='complete';
    }

    private function request(string $method,string $path,array $data=[]): array {
        return ['method'=>$method,'path'=>$path,'data'=>$data,'headers'=>$this->session];
    }
    private function require_state(string $state): void {
        if ($this->state!==$state) throw new \LogicException('Enrollment step is out of order.');
    }
}
