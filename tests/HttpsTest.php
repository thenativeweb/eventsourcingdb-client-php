<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\TestCase;
use Thenativeweb\Eventsourcingdb\Tests\Trait\ServerTestTrait;

final class HttpsTest extends TestCase
{
    use ServerTestTrait {
        tearDown as tearDownServer;
    }

    private const OPENSSL_CONFIG = <<<'CONFIG'
        [req]
        distinguished_name = distinguishedName

        [distinguishedName]

        [authority]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign
        subjectKeyIdentifier = hash

        [thisHost]
        basicConstraints = critical, CA:FALSE
        subjectAltName = IP:127.0.0.1

        [anotherHost]
        basicConstraints = critical, CA:FALSE
        subjectAltName = DNS:example.com
        CONFIG;

    private string $directory;

    /**
     * @var array{certificate: OpenSSLCertificate, privateKey: OpenSSLAsymmetricKey}
     */
    private array $authority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/eventsourcingdb-https-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents("{$this->directory}/openssl.cnf", self::OPENSSL_CONFIG);

        $this->authority = $this->createCertificate('EventSourcingDB Test Authority', 'authority');
        openssl_x509_export_to_file($this->authority['certificate'], "{$this->directory}/authority.pem");
    }

    protected function tearDown(): void
    {
        $this->tearDownServer();

        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
    }

    public function testConnectsIfATrustedAuthorityIssuedTheCertificateForTheHost(): void
    {
        $result = $this->pingOverHttps($this->createCertificate('127.0.0.1', 'thisHost', $this->authority));

        $this->assertSame('pinged', $result);
        $this->assertSame('ended', $this->readServerReport());
    }

    public function testRefusesTheConnectionIfNoTrustedAuthorityIssuedTheCertificate(): void
    {
        $result = $this->pingOverHttps($this->createCertificate('127.0.0.1', 'thisHost'));

        $this->assertStringStartsWith('Internal HttpClient: cURL handle execution failed with error: ', $result);
        $this->assertSame('no request', $this->readServerReport(), 'Expected the client not to send the request.');
    }

    public function testRefusesTheConnectionIfTheCertificateIsForAnotherHost(): void
    {
        $result = $this->pingOverHttps($this->createCertificate('example.com', 'anotherHost', $this->authority));

        $this->assertStringStartsWith('Internal HttpClient: cURL handle execution failed with error: ', $result);
        $this->assertSame('no request', $this->readServerReport(), 'Expected the client not to send the request.');
    }

    /**
     * @param array{certificate: OpenSSLCertificate, privateKey: OpenSSLAsymmetricKey}|null $issuer the authority that issues the certificate, or null for a self-signed one
     *
     * @return array{certificate: OpenSSLCertificate, privateKey: OpenSSLAsymmetricKey}
     */
    private function createCertificate(string $commonName, string $extensions, ?array $issuer = null): array
    {
        $options = [
            'config' => "{$this->directory}/openssl.cnf",
            'digest_alg' => 'sha256',
            'x509_extensions' => $extensions,
        ];

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey, (string) openssl_error_string());

        $request = openssl_csr_new([
            'commonName' => $commonName,
        ], $privateKey, $options);
        $this->assertNotFalse($request, (string) openssl_error_string());

        $certificate = openssl_csr_sign(
            $request,
            $issuer['certificate'] ?? null,
            $issuer['privateKey'] ?? $privateKey,
            1,
            $options,
            random_int(1, PHP_INT_MAX),
        );
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificate, (string) openssl_error_string());

        return [
            'certificate' => $certificate,
            'privateKey' => $privateKey,
        ];
    }

    /**
     * Serves https with the given certificate and pings the server with a
     * client that trusts the authority of this test, and nothing else.
     *
     * @param array{certificate: OpenSSLCertificate, privateKey: OpenSSLAsymmetricKey} $serverCertificate
     */
    private function pingOverHttps(array $serverCertificate): string
    {
        $tls = [
            'certificate' => "{$this->directory}/server.pem",
            'privateKey' => "{$this->directory}/server.key",
        ];
        openssl_x509_export_to_file($serverCertificate['certificate'], $tls['certificate']);
        openssl_pkey_export_to_file($serverCertificate['privateKey'], $tls['privateKey'], null, [
            'config' => "{$this->directory}/openssl.cnf",
        ]);

        $address = $this->startServerWithResponses([
            $this->response(
                ['{"type":"io.eventsourcingdb.api.ping-received"}'],
                interval: 0.0,
                holdFor: 0.0,
                contentType: 'application/json',
            ),
        ], $tls);

        $client = proc_open(
            [
                PHP_BINARY,
                // PHP hands this file to every cURL handle as the only
                // authorities to trust, and it prefers it to curl.cainfo.
                '-d',
                "openssl.cafile={$this->directory}/authority.pem",
                __DIR__ . '/Client/pingClient.php',
                "https://{$address}",
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            $pipes,
        );
        $this->assertIsResource($client);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($client);

        return trim($output);
    }
}
