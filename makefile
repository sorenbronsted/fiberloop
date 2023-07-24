
name = fiberloop
image:
	docker build -t $(name) .

bash:
	docker run -it $(name) bash

clean:
	docker rm $(shell docker ps -aq)

test:
	docker run --rm --interactive --tty --volume ${PWD}:/app composer run test

composer:
	docker run --rm --interactive --tty --volume ${PWD}:/app composer ${CMD}
