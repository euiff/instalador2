<?php

declare(strict_types=1);

require_once __DIR__.'/CronTasks.php';
require_once __DIR__.'/AdvancedCronTasks.php';

final class AutomationRunner
{
    public function __construct(private PDO $pdo) {}

    public function run(string $source='cron',?string $churchId=null): array
    {
        $id=app_uuid();
        $started=microtime(true);

        $q=$this->pdo->prepare('INSERT INTO automation_runs(id,church_id,run_source,status,started_at) VALUES(?,?,?,"running",NOW())');
        $q->execute([$id,$churchId,$source]);

        try{
            $basic=(new CronTasks($this->pdo,$churchId))->run();
            $advanced=(new AdvancedCronTasks($this->pdo,$churchId))->run();
            $result=['basic'=>$basic,'advanced'=>$advanced];
            $duration=(int)round((microtime(true)-$started)*1000);

            $q=$this->pdo->prepare('UPDATE automation_runs SET status="success",result_json=?,finished_at=NOW(),duration_ms=? WHERE id=?');
            $q->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$duration,$id]);

            return ['ok'=>true,'run_id'=>$id,'duration_ms'=>$duration,'result'=>$result];
        }catch(Throwable $e){
            $duration=(int)round((microtime(true)-$started)*1000);
            try{
                $q=$this->pdo->prepare('UPDATE automation_runs SET status="error",error_message=?,finished_at=NOW(),duration_ms=? WHERE id=?');
                $q->execute([$e->getMessage(),$duration,$id]);
            }catch(Throwable){}
            throw $e;
        }
    }
}
