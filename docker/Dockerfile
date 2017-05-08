FROM php:7.0-fpm

RUN sed -i -e "s/;daemonize\s*=\s*yes/daemonize = no/g" /usr/local/etc/php-fpm.conf \
 && sed -i -e "s/;catch_workers_output\s*=\s*yes/catch_workers_output = yes/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i -e "s/pm.max_children = 5/pm.max_children = 9/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i -e "s/pm.start_servers = 2/pm.start_servers = 3/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i -e "s/pm.min_spare_servers = 1/pm.min_spare_servers = 2/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i -e "s/pm.max_spare_servers = 3/pm.max_spare_servers = 4/g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i -e "s/pm.max_requests = 500/pm.max_requests = 200/g" /usr/local/etc/php-fpm.d/www.conf

RUN apt-get update -qqy \
  && apt-get -qqy install wget ca-certificates apt-transport-https nginx supervisor ttf-wqy-zenhei ttf-unfonts-core \
    unzip git \
  && rm -rf /var/lib/apt/lists/* /var/cache/apt/*


RUN wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | apt-key add - \
  && echo "deb https://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google-chrome.list \
  && apt-get update -qqy \
  && apt-get -qqy install google-chrome-unstable \
  && rm /etc/apt/sources.list.d/google-chrome.list \
  && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

RUN useradd headless --shell /bin/bash --create-home \
  && usermod -a -G sudo headless \
  && echo 'ALL ALL = (ALL) NOPASSWD: ALL' >> /etc/sudoers \
  && echo 'headless:nopassword' | chpasswd

RUN mkdir /data

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && rm -rf /etc/nginx/sites-enabled/default \
 && mkdir -p /root/.ssh \
 && echo "Host *\n\tStrictHostKeyChecking no\n" >> /root/.ssh/config

VOLUME /code

WORKDIR /code

COPY files/supervisord.conf /etc/supervisord.conf

COPY files/entrypoint.sh /entrypoint.sh

COPY files/mink_vhost.conf /etc/nginx/sites-enabled/mink

ENTRYPOINT ["/entrypoint.sh"]

CMD ["bash"]
