.PHONY: build clean

build:
	GOOS=linux GOARCH=amd64 CGO_ENABLED=0 GOAMD64=v3 go build -ldflags="-s -w" -gcflags="-B" -o ../bin/cache-warmup ./cmd/cache-warmup

clean:
	rm -f ../bin/cache-warmup
