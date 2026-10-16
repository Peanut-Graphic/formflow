<?php
namespace ISF\Tests\Unit;

use ISF\PublicFormData;
use ISF\Tests\Unit\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-public-form-data.php';

final class PublicFormDataTest extends TestCase {
    public function testProviderSecretsAndLegacyFieldsNeverReachPublicProjection(): void {
        $stored = ['last_name'=>'SecretSurname','email'=>'secret@example.test','validated_street'=>'Hidden Street','validation_result'=>['secret'=>'raw'],'ca_no'=>'provider-id','fsr_no'=>'job-id'];
        $stored = PublicFormData::validated($stored, ['email'=>'secret@example.test'], ['last_name'=>'SecretSurname'], '12345', '20001', 'session', 1);
        $public = PublicFormData::project($stored, 'session', 1);
        $this->assertSame(['account_number'=>'12345','utility_no'=>'12345','zip_code'=>'20001'], $public);
        $this->assertSame('provider-id', $stored['ca_no']);
        $this->assertArrayNotHasKey('fsr_no', $stored);
        $this->assertArrayNotHasKey('validation_result', $stored);
        $this->assertSame('SecretSurname', $stored['_legacy_form_data']['last_name']);
    }

    public function testOnlyExplicitCallerFieldsAreEchoedAndAddressIsBounded(): void {
        $stored = PublicFormData::capture([], ['first_name'=>'Caller','email'=>'caller@example.test','address'=>['street'=>'My Street','private'=>'hidden','city'=>['nested'=>'bad']], '_provider_validation'=>['bad'=>true],'account_validated'=>true,'ca_no'=>'forged'], 'session', 1);
        $this->assertSame(['first_name'=>'Caller','email'=>'caller@example.test','address'=>['street'=>'My Street']], PublicFormData::project($stored, 'session', 1));
        $this->assertArrayNotHasKey('_provider_validation', $stored);
        $this->assertArrayNotHasKey('ca_no', $stored);
        $this->assertArrayNotHasKey('account_validated', $stored);
    }

    public function testLegacyUnsignedMarkersAndInjectedProvenanceAreRejected(): void {
        $stored = PublicFormData::capture(['first_name'=>'Legacy','_public_input'=>['version'=>1,'fields'=>['email'=>'secret@example.test'],'signature'=>'forged']], ['_public_input'=>['version'=>1,'fields'=>['last_name'=>'forged']],'phone'=>'5551234567'], 'session', 1);
        $this->assertSame(['phone'=>'5551234567'], PublicFormData::project($stored, 'session', 1));
        $this->assertSame('Legacy', $stored['_legacy_form_data']['first_name']);
        $next = PublicFormData::capture($stored, ['first_name'=>'Entered'], 'session', 1);
        $this->assertSame('Entered', PublicFormData::project($next, 'session', 1)['first_name']);
        $this->assertArrayNotHasKey('_legacy_form_data', $next['_legacy_form_data']);
    }

    public function testTamperedOrCrossSessionSignaturesFailClosed(): void {
        $stored = PublicFormData::capture([], ['last_name'=>'Caller'], 'session', 1);
        $this->assertSame([], PublicFormData::project($stored, 'other-session', 1));
        $this->assertSame([], PublicFormData::project($stored, 'session', 2));
        $stored['_public_input']['fields']['last_name'] = 'Tampered';
        $this->assertSame([], PublicFormData::project($stored, 'session', 1));
    }

    public function testRevalidationResetsContactConsentAndScheduleButRetainsDeviceChoice(): void {
        $stored = PublicFormData::capture(['enrollment_completed'=>false,'confirmation_number'=>'own-confirmation','fsr_no'=>'server-fsr'], ['last_name'=>'Old Contact','email'=>'old@example.test','agree_terms'=>true,'schedule_date'=>'2026-10-20','device_type'=>'dcu','thermostat_count'=>'2'], 'session', 1);
        $stored = PublicFormData::validated($stored, ['raw'=>'secret'], ['email'=>'provider@example.test'], 'new-account', '20002', 'session', 1);
        $public = PublicFormData::project($stored, 'session', 1);
        foreach (['last_name','email','agree_terms','schedule_date','fsr_no','_provider_validation'] as $key) {
            $this->assertArrayNotHasKey($key, $public);
        }
        $this->assertSame('dcu', $public['device_type']);
        $this->assertSame('new-account', $public['account_number']);
        $this->assertArrayNotHasKey('fsr_no', $stored);
        $this->assertFalse($public['enrollment_completed']);
        $this->assertArrayNotHasKey('confirmation_number', $public);
        $this->assertSame(['email'=>'provider@example.test'], $stored['_provider_validation']['customer']);
    }
    public function testMalformedStoredInputFailsClosed(): void {
        $stored = ['_public_input' => ['version' => 1, 'fields' => ['email' => "\xB1"], 'signature' => 'forged']];
        $this->assertSame([], PublicFormData::project($stored, 'session', 1));
    }

    public function testProEquipmentInputsSurviveCaptureAndPublicProjection(): void {
        $input = ['ac_units'=>'2','heat_pumps'=>'1','ac_heat_units'=>'3'];
        $stored = PublicFormData::capture([], $input, 'session', 1);
        $this->assertSame($input, PublicFormData::project($stored, 'session', 1));
        foreach ($input as $key=>$value) { $this->assertSame($value, $stored[$key]); }
    }

}
