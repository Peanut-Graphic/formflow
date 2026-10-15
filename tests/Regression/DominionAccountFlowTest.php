<?php
namespace ISF\Tests\Regression;
use ISF\Connectors\DominionPtr\AccountEnrollmentFlow;
use PHPUnit\Framework\TestCase;
require_once ISF_PLUGIN_DIR.'connectors/dominion-ptr/class-account-enrollment-flow.php';
final class DominionAccountFlowTest extends TestCase {
 private function flow(): AccountEnrollmentFlow {return new AccountEnrollmentFlow(['status'=>'found','data'=>['utility_no'=>'00123456','first_name'=>'Jo','enrollable_premises'=>[['id'=>9,'zip'=>'01234']]]]);}
 private function survey(): array {return ['id'=>17,'categories'=>[['questions'=>[['id'=>3,'mandatory'=>true,'control_type'=>'program_agreement','answers'=>[['id'=>1,'value'=>false]]]]]]];}
 private function ready(): AccountEnrollmentFlow {$flow=$this->flow();$flow->confirm_premise('9','person@example.com');$flow->accept_session(['portal_user'=>['id'=>12,'enrollment_token'=>'vendor-token']]);$flow->accept_qualification($this->survey());return $flow;}
 private function pending(): AccountEnrollmentFlow {$flow=$this->ready();$survey=$this->survey();$survey['categories'][0]['questions'][0]['answers'][0]['value']=true;$flow->qualification_completion_request($survey);return $flow;}
 public function test_mock_flow_creates_session_before_qualification_and_never_adds_otp(): void {
  $flow=$this->flow();$create=$flow->confirm_premise('9','person@example.com');
  $this->assertSame('portal_user/create_from_prospect',$create['path']);$this->assertSame('00123456',$create['data']['utility_no']);
  $flow->accept_session(['portal_user'=>['id'=>12,'enrollment_token'=>'vendor-token']]);
  $get=$flow->qualification_request();$this->assertSame('vendor-token',$get['headers']['X-Enrollment-Token']);$this->assertSame('9',$get['headers']['X-PortalUser-Premise']);
  $flow->accept_qualification($this->survey());$answered=$this->survey();$answered['categories'][0]['questions'][0]['answers'][0]['value']=true;
  $this->assertSame('single_page_premise_surveys/17/complete',$flow->qualification_completion_request($answered)['path']);
  $flow->accept_qualification_completion(['success'=>true,'data'=>['uuid'=>'test-uuid']]);
  $this->assertSame('enrollments/test-uuid',$flow->enrollment_request()['path']);
  $flow->accept_enrollment(['id'=>'test-uuid','programs'=>[['id'=>42,'selected_rate_contract_id'=>6]]]);
  $plan=$flow->enrollment_submission_plan();$this->assertTrue($plan['requires_vendor_confirmation']);$this->assertSame(42,$plan['data']['programs'][0]['id']);
  $flow->accept_enrollment_completion(['success'=>true]);$this->assertSame('complete',$flow->status());
 }
 public function test_vendor_reply_cannot_skip_required_terms(): void {$flow=$this->ready();$this->expectException(\LogicException::class);$flow->accept_qualification_completion(['success'=>true,'data'=>['uuid'=>'test-uuid']]);}
 public function test_string_false_cannot_accept_terms(): void {$flow=$this->ready();$survey=$this->survey();$survey['categories'][0]['questions'][0]['answers'][0]['value']='false';$this->expectException(\InvalidArgumentException::class);$flow->qualification_completion_request($survey);}
 public function test_unknown_premise_is_rejected(): void {$this->expectException(\InvalidArgumentException::class);$this->flow()->confirm_premise('999','person@example.com');}
 public function test_terms_are_not_automatically_accepted(): void {$this->expectException(\InvalidArgumentException::class);$this->ready()->qualification_completion_request($this->survey());}
 public function test_client_cannot_remove_required_question(): void {$this->expectException(\InvalidArgumentException::class);$flow=$this->ready();$flow->qualification_completion_request(['id'=>17,'categories'=>[]]);}
 public function test_client_cannot_weaken_required_flag(): void {$this->expectException(\InvalidArgumentException::class);$flow=$this->ready();$survey=$this->survey();$survey['categories'][0]['questions'][0]['mandatory']=false;$flow->qualification_completion_request($survey);}
 public function test_failed_enrollment_never_becomes_complete(): void {$flow=$this->pending();$flow->accept_qualification_completion(['success'=>true,'data'=>['uuid'=>'test-uuid']]);$flow->accept_enrollment(['id'=>'test-uuid']);$flow->enrollment_submission_plan();try{$flow->accept_enrollment_completion(['success'=>false]);$this->fail('Expected failure');}catch(\UnexpectedValueException $error){$this->assertSame('enrollment_ready',$flow->status());}}
 public function test_edits_invalidate_the_prior_session_and_account(): void {$flow=$this->ready();$flow->invalidate();$this->assertSame('invalidated',$flow->status());$this->expectException(\LogicException::class);$flow->qualification_request();}
 public function test_session_failure_does_not_advance(): void {$flow=$this->flow();$flow->confirm_premise('9','person@example.com');try{$flow->accept_session(['portal_user'=>['id'=>12]]);$this->fail('Expected failure');}catch(\UnexpectedValueException $error){$this->assertSame('premise_confirmed',$flow->status());}}
}
