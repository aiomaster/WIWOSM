#! /bin/sh
#$ -N wiwosm
#$ -l h_rt=6:00:00
#$ -l virtual_free=100M
#$ -l arch=*
#$ -l sql-toolserver=1
#$ -l sql-mapnik=1
#$ -m a
#$ -o $HOME/log/wiwosm.out
#$ -e $HOME/log/wiwosm.err


if [ `date +%u` -eq 3 ]
then
	php /home/master/gen_json_files.php full
else
	php /home/master/gen_json_files.php
fi
