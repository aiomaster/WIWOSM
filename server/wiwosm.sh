#! /bin/sh
#$ -N wiwosm
#$ -l h_rt=12:00:00
#$ -l virtual_free=100M
#$ -o /data/project/wiwosm/log/wiwosm.out
#$ -e /data/project/wiwosm/log/wiwosm.err


#if test `date +%u` -eq 3 
#then
#	php /data/project/wiwosm/WIWOSM/server/gen_json_files.php full
#else
#	php /data/project/wiwosm/WIWOSM/server/gen_json_files.php
#fi

#php /data/project/wiwosm/test.php

php /data/project/wiwosm/WIWOSM/server/gen_json_files.php
