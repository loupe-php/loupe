cd ~

# SQLite 3.7.17
wget https://sqlite.org/2013/sqlite-autoconf-3071700.tar.gz

tar zxvf sqlite-autoconf-3071700.tar.gz

cd sqlite-autoconf-3071700

./configure --prefix=$HOME/opt/sqlite

make
make install

export PATH=$HOME/opt/sqlite/bin:$PATH
export LD_LIBRARY_PATH=$HOME/opt/sqlite/lib
export LD_RUN_PATH=$HOME/opt/sqlite/lib

source ~/.bash_profile

which sqlite3
sqlite3 --version

php $GITHUB_WORKSPACE/test.php