#! /bin/sh
#$ -N wiwosmcleanup
#$ -l h_rt=2:00:00
#$ -l virtual_free=5M
#$ -l arch=*
#$ -m a
#$ -o $HOME/log/cleanup.out
#$ -e $HOME/log/cleanup.err

/usr/bin/rm -rf /mnt/user-store/wiwosm/geojsongz_old_remove

