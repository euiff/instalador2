<?php

declare(strict_types=1);

final class Auth
{
    public function __construct(private PDO $pdo) {}

    public function user(): ?array
    {
        $id=$_SESSION['user_id']??null;
        if(!$id)return null;
        $q=$this->pdo->prepare('SELECT id,email,full_name,active FROM users WHERE id=? LIMIT 1');
        $q->execute([$id]);$user=$q->fetch();
        return $user?:null;
    }

    public function login(string $email,string $password): bool
    {
        $q=$this->pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
        $q->execute([mb_strtolower(trim($email))]);$user=$q->fetch();
        if(!$user||!password_verify($password,$user['password_hash']))return false;

        session_regenerate_id(true);
        $_SESSION['user_id']=$user['id'];

        $churches=$this->churches((string)$user['id']);
        if($churches){
            $current=(string)($_SESSION['church_id']??'');
            $valid=false;
            foreach($churches as $church){
                if((string)$church['id']===$current){$valid=true;break;}
            }
            if(!$valid)$_SESSION['church_id']=$churches[0]['id'];
        }else{
            unset($_SESSION['church_id']);
        }
        return true;
    }

    public function register(string $email,string $password,string $fullName,string $churchName): array
    {
        $email=mb_strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('E-mail inválido.');
        if(mb_strlen($password)<6)throw new InvalidArgumentException('A senha deve ter ao menos 6 caracteres.');
        if(trim($churchName)==='')throw new InvalidArgumentException('Informe o nome da igreja.');

        $this->pdo->beginTransaction();
        try{
            $exists=$this->pdo->prepare('SELECT 1 FROM users WHERE email=?');$exists->execute([$email]);
            if($exists->fetchColumn())throw new RuntimeException('Este e-mail já está cadastrado.');

            $userId=app_uuid();$churchId=app_uuid();
            $slugBase=slugify($churchName);$slug=$slugBase;$n=2;
            while(true){
                $s=$this->pdo->prepare('SELECT 1 FROM churches WHERE slug=?');$s->execute([$slug]);
                if(!$s->fetchColumn())break;
                $slug=$slugBase.'-'.$n++;
            }

            $this->pdo->prepare('INSERT INTO users(id,email,password_hash,full_name) VALUES(?,?,?,?)')->execute([$userId,$email,password_hash($password,PASSWORD_DEFAULT),trim($fullName)]);
            $this->pdo->prepare('INSERT INTO profiles(id,user_id,full_name) VALUES(?,?,?)')->execute([app_uuid(),$userId,trim($fullName)]);
            $this->pdo->prepare("INSERT INTO churches(id,name,slug,plan,active,ai_enabled) VALUES(?,?,?,'free',1,1)")->execute([$churchId,trim($churchName),$slug]);
            $this->pdo->prepare('INSERT INTO church_members(id,church_id,user_id,is_owner,church_role) VALUES(?,?,?,?,?)')->execute([app_uuid(),$churchId,$userId,1,'owner']);
            $this->pdo->prepare("INSERT INTO user_roles(id,user_id,role) VALUES(?,?,'admin')")->execute([app_uuid(),$userId]);

            $this->pdo->commit();
            return ['user_id'=>$userId,'church_id'=>$churchId,'slug'=>$slug];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function churches(?string $userId=null): array
    {
        $userId??=$_SESSION['user_id']??'';
        if($userId==='')return [];

        if($this->hasRole('super_admin',$userId)){
            $q=$this->pdo->query("SELECT c.id,c.name,c.slug,c.logo_url,1 AS is_owner FROM churches c WHERE c.active=1 ORDER BY c.name");
            return $q->fetchAll();
        }

        $q=$this->pdo->prepare('SELECT c.id,c.name,c.slug,c.logo_url,cm.is_owner FROM church_members cm INNER JOIN churches c ON c.id=cm.church_id WHERE cm.user_id=? AND c.active=1 ORDER BY cm.is_owner DESC,c.name');
        $q->execute([$userId]);
        return $q->fetchAll();
    }

    public function currentChurch(): ?array
    {
        $churchId=$_SESSION['church_id']??null;
        $userId=$_SESSION['user_id']??null;
        if(!$churchId||!$userId)return null;

        if($this->hasRole('super_admin',(string)$userId)){
            $q=$this->pdo->prepare('SELECT c.*,1 AS is_owner FROM churches c WHERE c.id=? AND c.active=1 LIMIT 1');
            $q->execute([$churchId]);
            return $q->fetch()?:null;
        }

        $q=$this->pdo->prepare('SELECT c.*,cm.is_owner FROM church_members cm INNER JOIN churches c ON c.id=cm.church_id WHERE cm.user_id=? AND cm.church_id=? AND c.active=1 LIMIT 1');
        $q->execute([$userId,$churchId]);
        return $q->fetch()?:null;
    }

    public function selectChurch(string $churchId): bool
    {
        $userId=(string)($_SESSION['user_id']??'');
        if($userId==='')return false;

        if($this->hasRole('super_admin',$userId)){
            $q=$this->pdo->prepare('SELECT 1 FROM churches WHERE id=? AND active=1');
            $q->execute([$churchId]);
        }else{
            $q=$this->pdo->prepare('SELECT 1 FROM church_members cm INNER JOIN churches c ON c.id=cm.church_id WHERE cm.user_id=? AND cm.church_id=? AND c.active=1');
            $q->execute([$userId,$churchId]);
        }

        if(!$q->fetchColumn())return false;
        $_SESSION['church_id']=$churchId;
        return true;
    }

    public function currentMembership(): ?array
    {
        $userId=(string)($_SESSION['user_id']??'');
        $churchId=(string)($_SESSION['church_id']??'');
        if($userId===''||$churchId==='')return null;

        if($this->hasRole('super_admin',$userId)){
            return [
                'user_id'=>$userId,
                'church_id'=>$churchId,
                'is_owner'=>1,
                'church_role'=>'super_admin',
                'permissions'=>null,
            ];
        }

        $q=$this->pdo->prepare('SELECT cm.* FROM church_members cm INNER JOIN churches c ON c.id=cm.church_id WHERE cm.user_id=? AND cm.church_id=? AND c.active=1 LIMIT 1');
        $q->execute([$userId,$churchId]);
        return $q->fetch()?:null;
    }

    public function churchRole(): string
    {
        $membership=$this->currentMembership();
        if(!$membership)return '';
        if(($membership['church_role']??'')==='super_admin')return 'super_admin';
        if(!empty($membership['is_owner']))return 'owner';
        $role=strtolower(trim((string)($membership['church_role']??'admin')));
        return in_array($role,['owner','admin','pastor','secretary','treasurer'],true)?$role:'admin';
    }

    public function can(string $ability): bool
    {
        $userId=(string)($_SESSION['user_id']??'');
        if($userId===''||!$this->user())return false;
        if($this->hasRole('super_admin',$userId))return true;

        $membership=$this->currentMembership();
        if(!$membership)return false;

        $role=$this->churchRole();
        if(in_array($role,['owner','admin','pastor'],true))return true;

        $raw=$membership['permissions']??null;
        if(is_string($raw)&&$raw!==''){
            $decoded=json_decode($raw,true);
            if(is_array($decoded)&&array_key_exists($ability,$decoded)){
                return (bool)$decoded[$ability];
            }
        }

        $matrix=[
            'secretary'=>['dashboard','secretary','communications','needs'],
            'treasurer'=>['dashboard','finance','reports','needs'],
        ];
        return in_array($ability,$matrix[$role]??[],true);
    }

    public function requireAbility(string $ability): array
    {
        $user=$this->requireLogin();
        if(!$this->can($ability)){
            http_response_code(403);
            exit('Você não possui permissão para acessar este módulo.');
        }
        return $user;
    }

    public function roles(?string $userId=null): array
    {
        $userId??=$_SESSION['user_id']??'';
        if($userId==='')return [];
        $q=$this->pdo->prepare('SELECT role FROM user_roles WHERE user_id=? ORDER BY role');
        $q->execute([$userId]);
        return array_values(array_unique(array_map('strval',array_column($q->fetchAll(),'role'))));
    }

    public function hasRole(string $role,?string $userId=null): bool
    {
        return in_array($role,$this->roles($userId),true);
    }

    public function requireRole(string $role): array
    {
        $user=$this->requireLogin();
        if(!$this->hasRole($role,(string)$user['id'])){
            http_response_code(403);
            exit('Acesso restrito ao administrador Master.');
        }
        return $user;
    }

    public function requireLogin(): array
    {
        $user=$this->user();
        if(!$user)redirect('/login.php');
        return $user;
    }

    public function logout(): void
    {
        $_SESSION=[];
        if(ini_get('session.use_cookies')){
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
        }
        session_destroy();
    }
}
