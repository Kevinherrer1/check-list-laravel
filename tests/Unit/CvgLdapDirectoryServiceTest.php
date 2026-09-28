<?php

namespace Tests\Unit;

use App\Services\CvgLdapDirectoryService;
use PHPUnit\Framework\TestCase;

class CvgLdapDirectoryServiceTest extends TestCase
{
    public function test_parse_ldif_reads_cn_and_mail(): void
    {
        $ldif = <<<'LDIF'
dn: uid=maria,dc=pzo,dc=cvg,dc=com
cn: Maria Blanco
mail: maria.blanco@cvg.gob.ve

dn: uid=oscar,dc=pzo,dc=cvg,dc=com
displayName: Oscar Mendez
mail: oscar.mendez@cvg.gob.ve

dn: uid=bad,dc=pzo,dc=cvg,dc=com
cn: Sin Correo

dn: uid=dup,dc=pzo,dc=cvg,dc=com
cn: Maria Otra
mail: maria.blanco@cvg.gob.ve
LDIF;

        $rows = (new CvgLdapDirectoryService)->parseLdif($ldif);

        $this->assertCount(2, $rows);
        $this->assertSame('Maria Blanco', $rows[0]['nombre']);
        $this->assertSame('maria.blanco@cvg.gob.ve', $rows[0]['email']);
        $this->assertSame('Oscar Mendez', $rows[1]['nombre']);
        $this->assertSame('oscar.mendez@cvg.gob.ve', $rows[1]['email']);
    }
}
