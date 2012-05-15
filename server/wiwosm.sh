#! /bin/sh
#$ -N wiwosm
#$ -l h_rt=4:00:00
#$ -l virtual_free=200M
#$ -l sql-toolserver=1
#$ -l sql-mapnik=1
#$ -m a
#$ -o $HOME/log/wiwosm.out
#$ -e $HOME/log/wiwosm.err


### on wednesday do a full upgrade (takes 84min)
### otherdays just update one file for every article (takes 35 min)
if [ `date +%u` -eq 3 ]
then
	php /home/master/gen_json_files.php full
else
	php /home/master/gen_json_files.php
fi