<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $host=(string)($cfg['host']??$cfg['hostname']??$cfg['db_host']??'localhost');
        $port=(int)($cfg['port']??$cfg['db_port']??3306);
        $database=(string)($cfg['database']??$cfg['dbname']??$cfg['db_name']??$cfg['name']??'');
        $username=(string)($cfg['username']??$cfg['user']??$cfg['db_user']??'');
        $password=(string)($cfg['password']??$cfg['pass']??$cfg['db_pass']??'');
        $charset=(string)($cfg['charset']??'utf8mb4');

        if($database==='') throw new RuntimeException('Nome do banco de dados não configurado.');
        if($username==='') throw new RuntimeException('Usuário do banco de dados não configurado.');

        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$host,$port,$database,$charset);
        self::$pdo=new PDO($dsn,$username,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        self::$pdo->exec("SET time_zone = '-03:00'");
        return self::$pdo;
    }
}
