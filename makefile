
.PHONY: image bash clean test composer

name = fiberloop
container_label = bronsted.fiberloop

image:
	docker build -t $(name) .

bash:
	docker run --rm --label $(container_label) -it $(name) bash

clean:
	@containers=$$(docker ps -aq --filter "label=$(container_label)" --filter "status=exited" --filter "status=created") && \
	if [ -n "$$containers" ]; then docker rm $$containers; fi

test:
	docker run --rm --label $(container_label) --volume ${PWD}:/app composer run test

composer:
	docker run --rm --label $(container_label) -it --volume ${PWD}:/app composer ${CMD}
