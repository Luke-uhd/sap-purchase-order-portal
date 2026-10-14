<?php
declare(strict_types=1);
namespace App;

final class ServiceLayerClient {
    public function login(string $companyDb, string $userName, string $password): array {
        $r=$this->request('POST','/Login',null,['CompanyDB'=>$companyDb,'UserName'=>$userName,'Password'=>$password]);
        if ($r['status']<200 || $r['status']>=300) throw new \RuntimeException('Falha no login SAP: '.$this->message($r));
        $cookie=$this->cookies($r['headers']);
        if ($cookie==='') throw new \RuntimeException('A Service Layer não retornou B1SESSION.');
        return ['cookie'=>$cookie,'timeout'=>(int)($r['json']['SessionTimeout']??30)];
    }
    public function logout(string $cookie): void { $this->request('POST','/Logout',$cookie,[]); }
    public function get(string $path,string $cookie,?int $pageSize=null): array { return $this->request('GET',$path,$cookie,null,$pageSize); }
    public function patch(string $path,string $cookie,array $payload,array $headers=[]): array { return $this->request('PATCH',$path,$cookie,$payload,null,$headers); }

    public function queryDocumentLines(
        string $cookie,
        string $queryPath,
        string $queryOption,
        ?int $pageSize = null
    ): array {
        return $this->request(
            'POST',
            '/QueryService_PostQuery',
            $cookie,
            [
                'QueryPath' => $queryPath,
                'QueryOption' => $queryOption,
            ],
            $pageSize
        );
    }

    private function request(string $method,string $path,?string $cookie,?array $json,?int $pageSize=null,array $extra=[]): array {
        $ch=curl_init(rtrim((string)app_config('service_layer_url'),'/').$path);
        if (!$ch) throw new \RuntimeException('Não foi possível iniciar cURL.');
        $headers=['Accept: application/json'];
        if ($cookie!==null) $headers[]='Cookie: '.$cookie;
        if ($json!==null) $headers[]='Content-Type: application/json';
        if ($pageSize!==null && $pageSize>0) $headers[]='Prefer: odata.maxpagesize='.$pageSize;
        foreach($extra as $h) $headers[]=$h;
        $body=$json===null?null:json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>(bool)app_config('ssl_verify_peer'),CURLOPT_SSL_VERIFYHOST=>(int)app_config('ssl_verify_host')]);
        if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $raw=curl_exec($ch);$err=curl_error($ch);$no=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$hs=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);
        if($raw===false) throw new \RuntimeException("Erro cURL [{$no}]: {$err}");
        $headersRaw=substr($raw,0,$hs);$bodyRaw=substr($raw,$hs);$decoded=json_decode($bodyRaw,true);
        return ['status'=>$status,'headers'=>$headersRaw,'body'=>$bodyRaw,'json'=>json_last_error()===JSON_ERROR_NONE?$decoded:null];
    }
    private function cookies(string $headers): string { $cookies=[];foreach(preg_split('/\r\n|\n|\r/',$headers) as $line){if(stripos($line,'Set-Cookie:')!==0)continue;$pair=explode(';',trim(substr($line,11)),2)[0];if($pair!=='')$cookies[]=$pair;}return implode('; ',$cookies); }
    private function message(array $r): string { $j=$r['json'];$m=$j['error']['message']['value']??$j['error']['message']??$j['message']??$r['body'];return is_array($m)?(json_encode($m,JSON_UNESCAPED_UNICODE)?:'Erro desconhecido'):(string)$m; }
}
